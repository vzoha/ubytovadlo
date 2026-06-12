<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\ElectricityTariff;
use App\Entity\GuestDocument;
use App\Entity\Reservation;
use App\Entity\Setting;
use App\Entity\User;
use App\Entity\VatPeriod;
use App\Enum\Channel;
use App\Enum\CleaningType;
use App\Enum\DocumentType;
use App\Enum\ElectricitySource;
use App\Enum\ReservationStatus;
use App\Invoice\InvoiceService;
use App\Repository\CleaningRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Naseeduje pestrá, prezentovatelná demo data (dev only).
 *
 * Cíl: každá stránka interního UI (dashboard, /rezervace, detail, /ekonomika,
 * /dph, fakturace) vypadá plně a reprezentativně pro screenshoty. Na rozdíl od
 * app:dev:import-fixtures (replay .eml přes dispatcher → jen needs_details)
 * staví kompletní rezervace napříč kanály a stavy a vystavuje reálné faktury
 * přes InvoiceService (skutečné PDF + QR + číselná řada).
 *
 * Časová kotva: pobyty jsou rozložené kolem "dneška" tak, aby vznikl mix
 * COMPLETED / IN_PROGRESS / CONFIRMED / NEEDS_DETAILS / CANCELLED.
 */
#[AsCommand(name: 'app:dev:seed-demo', description: 'Naseeduje pestrá demo data pro prezentaci/screenshoty (dev only).')]
class DevSeedDemoCommand extends Command
{
    /** Statický EUR kurz pro DPH/Booking základ — ať seed nezávisí na síti pro uložené hodnoty. */
    private const EUR_RATE = '24.80';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly InvoiceService $invoices,
        private readonly CleaningRepository $cleanings,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly string $appEnv,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Spustit i mimo dev prostředí.')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-mail demo uživatele.', 'admin@example.com')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Heslo demo uživatele.', 'heslo123');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->appEnv === 'prod' && !$input->getOption('force')) {
            $io->error('Odmítám běžet v prod prostředí bez --force. Tohle smaže data!');

            return Command::FAILURE;
        }

        $io->warning('Tohle SMAŽE všechna provozní data v této DB a nahradí je demo daty.');
        $this->wipe($io);

        $this->seedSupport($input, $io);
        $reservations = $this->seedReservations($io);
        $this->seedVatPeriods($io);

        $io->success(sprintf('Hotovo. Naseedováno %d rezervací + faktury, úklidy, elektřina, DPH.', count($reservations)));
        $io->writeln('Přihlášení: <info>' . $input->getOption('email') . '</info> / <info>' . $input->getOption('password') . '</info>');

        return Command::SUCCESS;
    }

    private function wipe(SymfonyStyle $io): void
    {
        $tables = [
            'invoice_line', 'invoice', 'cleaning', 'guest_document', 'airbnb_statement',
            'booking_monthly_invoice', 'vat_period', 'electricity_reading', 'electricity_tariff',
            'email_log', 'reservation', 'app_user', 'setting', 'accommodation_profile',
        ];
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            try {
                $this->connection->executeStatement('TRUNCATE TABLE ' . $table);
            } catch (\Throwable) {
                // tabulka nemusí existovat ve všech verzích schématu — přeskoč
            }
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $io->writeln('  DB vyčištěna.');
    }

    private function seedSupport(InputInterface $input, SymfonyStyle $io): void
    {
        $user = new User($input->getOption('email'));
        $user->setPassword($this->hasher->hashPassword($user, $input->getOption('password')));
        $this->em->persist($user);

        $tariff = new ElectricityTariff(new \DateTimeImmutable('2025-01-01'), '6.80', '3.40');
        $tariff->setNote('Demo tarif (D57d).');
        $this->em->persist($tariff);

        $this->em->persist((new Setting('recreation_fee.per_adult_night', '15'))
            ->setNote('Rekreační poplatek obce — Kč / dospělý / noc.'));

        $this->em->flush();
        $io->writeln('  Uživatel, tarif a nastavení vytvořeny.');
    }

    /**
     * @return Reservation[]
     */
    private function seedReservations(SymfonyStyle $io): array
    {
        $specs = $this->specs();
        $created = [];
        foreach ($specs as $spec) {
            $created[] = $this->buildReservation($spec);
        }
        $this->em->flush(); // přiřadí ID (IDENTITY) — potřebné pro OneToOne úklid i faktury

        foreach ($created as $i => $reservation) {
            $this->maybeAddCleaning($reservation, $specs[$i]);
            $this->maybeAddGuestDocuments($reservation, $specs[$i]);
        }
        $this->em->flush();

        // Faktury přes InvoiceService (persistuje a renderuje PDF sám).
        foreach ($created as $i => $reservation) {
            $this->issueInvoices($reservation, $specs[$i]);
        }

        $io->writeln(sprintf('  %d rezervací + faktury vystaveny.', count($created)));

        return $created;
    }

    /** @param array<string, mixed> $s */
    private function buildReservation(array $s): Reservation
    {
        $checkIn = new \DateTimeImmutable($s['in']);
        $r = new Reservation($s['channel'], $checkIn);
        if (isset($s['out'])) {
            $r->setCheckOut(new \DateTimeImmutable($s['out']));
        }
        $r->setStatus($this->resolveStatus($s));
        $r->setBillingMode($s['billing']);
        $r->setCheckInTime(new \DateTimeImmutable('15:00'));
        $r->setCheckOutTime(new \DateTimeImmutable('10:00'));
        $r->setBookedAt($checkIn->modify('-' . ($s['leadDays'] ?? 40) . ' days'));
        $r->setAcquisitionSource($s['acq'] ?? null);

        if (isset($s['gateway'])) {
            $r->setMotopressPaymentGateway($s['gateway']);
            $r->setMotopressExternalId('mphb-' . $s['ext']);
        } else {
            $r->setExternalId($s['ext']);
        }

        // Údaje hosta (NEEDS_DETAILS je úmyslně nemá).
        if (!($s['needsDetails'] ?? false)) {
            $r->setGuestName($s['name']);
            $r->setGuestEmail($s['email'] ?? null);
            $r->setGuestPhone($s['phone'] ?? null);
            $r->setGuestStreet($s['street'] ?? null);
            $r->setGuestCity($s['city'] ?? null);
            $r->setGuestZip($s['zip'] ?? null);
            $r->setGuestCountry($s['country'] ?? 'CZ');
            $r->setGuestCompanyName($s['company'] ?? null);
            $r->setGuestIco($s['ico'] ?? null);
            $r->setGuestDic($s['dic'] ?? null);
            $r->setGuestRegion($s['region'] ?? null);
            $r->setGuestsAdult($s['adults'] ?? 2);
            $r->setGuestsChild($s['children'] ?? 0);
            $r->setGuestsInfant($s['infants'] ?? 0);
            $r->setPriceTotal($s['price']);
            $r->setPriceCurrency($s['currency'] ?? 'CZK');
        }

        $r->setHasPet($s['pet'] ?? false);
        if ($s['pet'] ?? false) {
            $r->setPetsNote('Malý pes, hlídaný.');
        }
        $r->setNeedsBabyCot($s['cot'] ?? false);

        if (isset($s['vtKwh'])) {
            $r->setVtKwh($s['vtKwh']);
            $r->setNtKwh($s['ntKwh']);
            $r->setElectricitySource(ElectricitySource::MEASURED);
        }

        $this->applyOtaCommissionAndVat($r, $s);
        $this->em->persist($r);

        return $r;
    }

    /** @param array<string, mixed> $s */
    private function resolveStatus(array $s): ReservationStatus
    {
        if (isset($s['status'])) {
            return $s['status'];
        }
        if ($s['needsDetails'] ?? false) {
            return ReservationStatus::NEEDS_DETAILS;
        }
        $today = new \DateTimeImmutable('today');
        $in = new \DateTimeImmutable($s['in']);
        $out = isset($s['out']) ? new \DateTimeImmutable($s['out']) : $in;
        if ($out < $today) {
            return ReservationStatus::COMPLETED;
        }
        if ($in <= $today && $out >= $today) {
            return ReservationStatus::IN_PROGRESS;
        }

        return ReservationStatus::CONFIRMED;
    }

    /** @param array<string, mixed> $s */
    private function applyOtaCommissionAndVat(Reservation $r, array $s): void
    {
        if (($s['needsDetails'] ?? false) || $r->getChannel() === Channel::WEB) {
            return;
        }
        $duzp = new \DateTimeImmutable($s['in']);

        if ($r->getChannel() === Channel::BOOKING) {
            // Provize ~15 % z ceny v EUR; základ DPH = provize × kurz ČNB.
            $commissionEur = $this->money((float) $s['price'] * 0.15);
            $r->setCommissionAmount($commissionEur);
            $r->setCommissionCurrency('EUR');
            $r->setVatCnbRate(self::EUR_RATE);
            $r->setVatCnbRateDate($duzp);
            $baseCzk = $this->money((float) $commissionEur * (float) self::EUR_RATE);
            $r->setVatBaseCzk($baseCzk);
            $r->setVatAmountCzk($this->money((float) $baseCzk * 0.21));
            $r->setVatDuzp($duzp);

            return;
        }

        // Airbnb: provize ~3 % v CZK; základ DPH = provize.
        $commissionCzk = $this->money((float) $s['price'] * 0.03);
        $r->setCommissionAmount($commissionCzk);
        $r->setCommissionCurrency('CZK');
        $r->setNetPayout($this->money((float) $s['price'] - (float) $commissionCzk));
        $r->setVatBaseCzk($commissionCzk);
        $r->setVatAmountCzk($this->money((float) $commissionCzk * 0.21));
        $r->setVatDuzp($duzp);

        // Reálná výplata u uskutečněných pobytů (→ faktura se vystaví rovnou jako uhrazená).
        if ($r->getStatus() === ReservationStatus::COMPLETED && isset($s['out'])) {
            $r->setPayoutAmount($r->getNetPayout());
            $r->setPayoutSentAt((new \DateTimeImmutable($s['out']))->modify('+2 days'));
            $r->setPayoutReference('AIRMD' . $s['ext']);
        }
    }

    /** @param array<string, mixed> $s */
    private function maybeAddCleaning(Reservation $r, array $s): void
    {
        // Úklid (OWNER) zakládá ke každé rezervaci automaticky ReservationCleaningListener.
        // My jen upravíme ten existující na konkrétní typ/cenu z demo specu.
        if (!isset($s['clean'])) {
            return;
        }
        $cleaning = $this->cleanings->findForReservation($r);
        if ($cleaning === null) {
            return;
        }
        [$type, $cost, $payout] = $s['clean'];
        $cleaning->setType($type)->setCostCzk($cost)->setPayoutCzk($payout);
        // Uskutečněné úklidy jsou vyplacené; budoucí (plán) zatím ne.
        if ($r->getStatus() === ReservationStatus::COMPLETED && $payout > 0) {
            $cleaning->setPaidAt((new \DateTimeImmutable($s['out']))->modify('+1 day'));
        }
    }

    /** @param array<string, mixed> $s */
    private function maybeAddGuestDocuments(Reservation $r, array $s): void
    {
        if (empty($s['docs'])) {
            return;
        }
        $r->setCheckinToken(str_repeat($s['ext'][0] ?? 'a', 64));
        foreach ($s['docs'] as $doc) {
            $gd = new GuestDocument($r, $doc[0], $doc[1], new \DateTimeImmutable($doc[2]));
            $gd->setIsCzechCitizen($doc[3] === 'CZ');
            if ($doc[3] !== 'CZ') {
                $gd->setNationalityCode($doc[3]);
                $gd->setDocumentType(DocumentType::PASSPORT);
                $gd->setDocumentNumber($doc[4] ?? null);
            }
            $this->em->persist($gd);
        }
    }

    /** @param array<string, mixed> $s */
    private function issueInvoices(Reservation $r, array $s): void
    {
        $mode = $s['inv'] ?? 'none';
        if ($mode === 'none') {
            return;
        }
        $issuedAt = $this->invoiceIssueDate($s);

        try {
            switch ($mode) {
                case 'deposit_final':
                    $deposit = $this->invoices->issueDeposit($r, $r->getBookedAt());
                    $this->invoices->markPaid($deposit, $r->getBookedAt()->modify('+3 days'));
                    $final = $this->invoices->issueFinal($r, $deposit, $issuedAt);
                    if ($s['paid'] ?? true) {
                        $this->invoices->markPaid($final, $issuedAt->modify('+1 day'));
                    }
                    break;

                case 'deposit_only':
                    $deposit = $this->invoices->issueDeposit($r, $r->getBookedAt());
                    $this->invoices->markPaid($deposit, $r->getBookedAt()->modify('+3 days'));
                    break;

                case 'full':
                    $invoice = $this->invoices->issueFull($r, $issuedAt);
                    // Airbnb se přes payoutSentAt označí jako uhrazená sama.
                    if (($s['paid'] ?? true) && $invoice->getPaidAt() === null) {
                        $this->invoices->markPaid($invoice, $issuedAt->modify('+2 days'));
                    }
                    break;
            }
        } catch (\Throwable $e) {
            // Faktura nesmí shodit celý seed (např. ČNB dočasně nedostupné u EUR).
            // Rezervace zůstane bez faktury — UI to korektně zobrazí jako "k vystavení".
        }
    }

    /** @param array<string, mixed> $s */
    private function invoiceIssueDate(array $s): \DateTimeImmutable
    {
        $today = new \DateTimeImmutable('today');
        $in = new \DateTimeImmutable($s['in']);

        return $in < $today ? $in : $today;
    }

    private function seedVatPeriods(SymfonyStyle $io): void
    {
        /** @var array<string, array{base: string, vat: string}> $byMonth */
        $byMonth = [];
        $reservations = $this->em->getRepository(Reservation::class)->findAll();
        foreach ($reservations as $r) {
            if ($r->getVatDuzp() === null || $r->getVatAmountCzk() === null) {
                continue;
            }
            $key = $r->getVatDuzp()->format('Y-m');
            // Budoucí DUZP (potvrzené nadcházející OTA) do DPH ještě nepatří — služba nebyla přijata.
            if ($key > (new \DateTimeImmutable('today'))->format('Y-m')) {
                continue;
            }
            $byMonth[$key] ??= ['base' => '0.00', 'vat' => '0.00'];
            $byMonth[$key]['base'] = bcadd($byMonth[$key]['base'], $r->getVatBaseCzk() ?? '0.00', 2);
            $byMonth[$key]['vat'] = bcadd($byMonth[$key]['vat'], $r->getVatAmountCzk(), 2);
        }

        $currentMonth = (new \DateTimeImmutable('today'))->format('Y-m');
        foreach ($byMonth as $ym => $sums) {
            [$year, $month] = array_map('intval', explode('-', $ym));
            $period = new VatPeriod($year, $month);
            $period->setSumBaseCzk($sums['base']);
            $period->setSumVatCzk($sums['vat']);
            // Starší měsíce už podané; aktuální a předchozí necháme pending (čekají na doklady).
            if ($ym < $currentMonth && $ym < (new \DateTimeImmutable('today'))->modify('-1 month')->format('Y-m')) {
                $period->setFiledAt((new \DateTimeImmutable($ym . '-20'))->modify('+1 month'));
                $period->setPaidAt((new \DateTimeImmutable($ym . '-25'))->modify('+1 month'));
                $period->setPaidAmountCzk($sums['vat']);
            }
            $this->em->persist($period);
        }
        $this->em->flush();
        $io->writeln(sprintf('  %d DPH období agregováno.', count($byMonth)));
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * Definice demo rezervací. Stav se odvodí z dat vůči dnešku (pokud není 'status').
     *
     * @return array<int, array<string, mixed>>
     */
    private function specs(): array
    {
        return [
            // ── Uskutečněné pobyty (COMPLETED) ───────────────────────────────
            [
                'channel' => Channel::WEB, 'billing' => \App\Enum\BillingMode::STANDARD_WITH_DEPOSIT, 'gateway' => 'bank',
                'ext' => '101', 'in' => '2026-01-09', 'out' => '2026-01-12', 'name' => 'Jan Novák',
                'email' => 'jan.novak@email.cz', 'phone' => '+420 603 111 222', 'street' => 'Lipová 14',
                'city' => 'Tábor', 'zip' => '39001', 'adults' => 2, 'children' => 1, 'price' => '4500.00',
                'acq' => 'Google', 'vtKwh' => 32, 'ntKwh' => 20, 'clean' => [CleaningType::CLEANER, 700, 700],
                'inv' => 'deposit_final',
            ],
            [
                'channel' => Channel::AIRBNB, 'billing' => \App\Enum\BillingMode::AIRBNB,
                'ext' => '102', 'in' => '2026-01-23', 'out' => '2026-01-26', 'name' => 'Markéta Dvořáková',
                'region' => 'Praha', 'adults' => 2, 'price' => '4200.00', 'acq' => 'Airbnb',
                'vtKwh' => 30, 'ntKwh' => 19, 'clean' => [CleaningType::OWNER, 700, 0], 'inv' => 'full',
            ],
            [
                'channel' => Channel::BOOKING, 'billing' => \App\Enum\BillingMode::BOOKING_COM,
                'ext' => '7000000201', 'in' => '2026-02-06', 'out' => '2026-02-09', 'name' => 'Klaus Müller',
                'street' => 'Hauptstraße 5', 'city' => 'Passau', 'zip' => '94032', 'country' => 'DE',
                'adults' => 2, 'price' => '210.00', 'currency' => 'EUR', 'acq' => 'Booking.com',
                'vtKwh' => 28, 'ntKwh' => 18, 'clean' => [CleaningType::CLEANER_LAUNDRY, 800, 800], 'inv' => 'full',
            ],
            [
                'channel' => Channel::WEB, 'billing' => \App\Enum\BillingMode::FKSP, 'gateway' => 'cash',
                'ext' => '103', 'in' => '2026-02-13', 'out' => '2026-02-15', 'name' => 'ČSOB a.s.',
                'company' => 'Československá obchodní banka, a.s.', 'ico' => '00001350', 'dic' => 'CZ00001350',
                'email' => 'fksp@csob.cz', 'street' => 'Radlická 333/150', 'city' => 'Praha', 'zip' => '15057',
                'adults' => 4, 'price' => '3600.00', 'acq' => 'doporučení', 'vtKwh' => 22, 'ntKwh' => 14,
                'clean' => [CleaningType::CLEANER, 700, 700], 'inv' => 'full',
            ],
            [
                'channel' => Channel::WEB, 'billing' => \App\Enum\BillingMode::ADMIN_BOOKING, 'gateway' => 'manual',
                'ext' => '104', 'in' => '2026-02-27', 'out' => '2026-03-01', 'name' => 'Petr Zahradník',
                'email' => 'petr.z@email.cz', 'street' => 'Nádražní 8', 'city' => 'České Budějovice', 'zip' => '37001',
                'adults' => 2, 'children' => 2, 'price' => '0.00', 'acq' => 'rodina', 'vtKwh' => 26, 'ntKwh' => 16,
                'clean' => [CleaningType::OWNER, 700, 0], 'inv' => 'none',
            ],
            [
                'channel' => Channel::AIRBNB, 'billing' => \App\Enum\BillingMode::AIRBNB,
                'ext' => '105', 'in' => '2026-03-06', 'out' => '2026-03-09', 'name' => 'Lucie Horáková',
                'region' => 'Brno', 'adults' => 2, 'children' => 1, 'price' => '4500.00', 'acq' => 'Airbnb',
                'vtKwh' => 31, 'ntKwh' => 20, 'clean' => [CleaningType::CLEANER, 700, 700], 'inv' => 'full',
            ],
            [
                'channel' => Channel::BOOKING, 'billing' => \App\Enum\BillingMode::BOOKING_COM,
                'ext' => '7000000202', 'in' => '2026-03-20', 'out' => '2026-03-23', 'name' => 'Anke de Vries',
                'street' => 'Keizersgracht 12', 'city' => 'Amsterdam', 'zip' => '1015', 'country' => 'NL',
                'adults' => 2, 'price' => '225.00', 'currency' => 'EUR', 'acq' => 'Booking.com',
                'vtKwh' => 29, 'ntKwh' => 19, 'clean' => [CleaningType::CLEANER_LAUNDRY, 800, 800], 'inv' => 'full',
            ],
            [
                'channel' => Channel::BOOKING, 'billing' => \App\Enum\BillingMode::BOOKING_COM, 'status' => ReservationStatus::CANCELLED,
                'ext' => '7000000203', 'in' => '2026-03-13', 'out' => '2026-03-15', 'name' => 'Tomáš Marek',
                'country' => 'CZ', 'adults' => 2, 'price' => '150.00', 'currency' => 'EUR', 'acq' => 'Booking.com',
                'inv' => 'none',
            ],
            [
                'channel' => Channel::WEB, 'billing' => \App\Enum\BillingMode::STANDARD_WITH_DEPOSIT, 'gateway' => 'bank',
                'ext' => '106', 'in' => '2026-04-02', 'out' => '2026-04-06', 'name' => 'Eva Pospíšilová',
                'email' => 'eva.p@email.cz', 'phone' => '+420 777 333 444', 'street' => 'Sadová 22',
                'city' => 'Písek', 'zip' => '39701', 'adults' => 2, 'children' => 2, 'price' => '6400.00',
                'acq' => 'návrat', 'vtKwh' => 40, 'ntKwh' => 25, 'clean' => [CleaningType::CLEANER, 700, 700],
                'inv' => 'deposit_final',
            ],
            [
                'channel' => Channel::AIRBNB, 'billing' => \App\Enum\BillingMode::AIRBNB,
                'ext' => '107', 'in' => '2026-04-10', 'out' => '2026-04-12', 'name' => 'Jakub Černý',
                'region' => 'Plzeň', 'adults' => 2, 'price' => '3000.00', 'acq' => 'Airbnb', 'pet' => true,
                'vtKwh' => 20, 'ntKwh' => 13, 'clean' => [CleaningType::EXTERNAL, 800, 800], 'inv' => 'full',
            ],
            [
                'channel' => Channel::BOOKING, 'billing' => \App\Enum\BillingMode::BOOKING_COM,
                'ext' => '7000000204', 'in' => '2026-04-24', 'out' => '2026-04-27', 'name' => 'Mária Kováčová',
                'street' => 'Hlavná 3', 'city' => 'Košice', 'zip' => '04001', 'country' => 'SK',
                'adults' => 3, 'price' => '240.00', 'currency' => 'EUR', 'acq' => 'Booking.com',
                'vtKwh' => 33, 'ntKwh' => 21, 'clean' => [CleaningType::CLEANER, 700, 700], 'inv' => 'full',
            ],
            [
                'channel' => Channel::WEB, 'billing' => \App\Enum\BillingMode::STANDARD_WITH_DEPOSIT, 'gateway' => 'bank',
                'ext' => '108', 'in' => '2026-05-01', 'out' => '2026-05-04', 'name' => 'Martin Kučera',
                'email' => 'martin.kucera@email.cz', 'phone' => '+420 605 222 333', 'street' => 'Polní 7',
                'city' => 'Jindřichův Hradec', 'zip' => '37701', 'adults' => 2, 'price' => '4800.00',
                'acq' => 'Google', 'vtKwh' => 30, 'ntKwh' => 19, 'clean' => [CleaningType::CLEANER, 700, 700],
                'inv' => 'deposit_final',
            ],
            [
                'channel' => Channel::AIRBNB, 'billing' => \App\Enum\BillingMode::AIRBNB,
                'ext' => '109', 'in' => '2026-05-08', 'out' => '2026-05-11', 'name' => 'Veronika Marková',
                'region' => 'Olomouc', 'adults' => 2, 'children' => 1, 'price' => '4650.00', 'acq' => 'Airbnb',
                'vtKwh' => 32, 'ntKwh' => 20, 'clean' => [CleaningType::OWNER, 700, 0], 'inv' => 'full',
            ],
            [
                'channel' => Channel::BOOKING, 'billing' => \App\Enum\BillingMode::BOOKING_COM,
                'ext' => '7000000205', 'in' => '2026-05-15', 'out' => '2026-05-18', 'name' => 'Stefan Wagner',
                'street' => 'Lindenweg 9', 'city' => 'Dresden', 'zip' => '01067', 'country' => 'DE',
                'adults' => 2, 'infants' => 1, 'price' => '255.00', 'currency' => 'EUR', 'acq' => 'Booking.com',
                'cot' => true, 'vtKwh' => 30, 'ntKwh' => 19, 'clean' => [CleaningType::CLEANER_LAUNDRY, 800, 800],
                'inv' => 'full',
            ],
            [
                'channel' => Channel::WEB, 'billing' => \App\Enum\BillingMode::FKSP, 'gateway' => 'cash',
                'ext' => '110', 'in' => '2026-05-22', 'out' => '2026-05-24', 'name' => 'Škoda Auto a.s.',
                'company' => 'ŠKODA AUTO a.s.', 'ico' => '00177041', 'dic' => 'CZ00177041',
                'email' => 'fksp@skoda-auto.cz', 'street' => 'tř. Václava Klementa 869', 'city' => 'Mladá Boleslav',
                'zip' => '29301', 'adults' => 4, 'price' => '3600.00', 'acq' => 'doporučení', 'vtKwh' => 24, 'ntKwh' => 15,
                'clean' => [CleaningType::CLEANER, 700, 700], 'inv' => 'full', 'paid' => false,
            ],
            [
                'channel' => Channel::AIRBNB, 'billing' => \App\Enum\BillingMode::AIRBNB,
                'ext' => '111', 'in' => '2026-05-29', 'out' => '2026-06-01', 'name' => 'Ondřej Beneš',
                'region' => 'Hradec Králové', 'adults' => 2, 'price' => '4500.00', 'acq' => 'Airbnb',
                'vtKwh' => 31, 'ntKwh' => 20, 'clean' => [CleaningType::CLEANER, 700, 700], 'inv' => 'full',
            ],
            [
                'channel' => Channel::WEB, 'billing' => \App\Enum\BillingMode::ADMIN_BOOKING, 'gateway' => 'manual',
                'ext' => '112', 'in' => '2026-06-03', 'out' => '2026-06-06', 'name' => 'Hana Zahradníková',
                'email' => 'hana.z@email.cz', 'street' => 'Krátká 2', 'city' => 'Trhové Sviny', 'zip' => '37401',
                'adults' => 2, 'children' => 1, 'price' => '3900.00', 'acq' => 'rodina', 'vtKwh' => 28, 'ntKwh' => 18,
                'clean' => [CleaningType::OWNER, 700, 0], 'inv' => 'full',
            ],

            // ── Právě probíhá (IN_PROGRESS) ──────────────────────────────────
            [
                'channel' => Channel::WEB, 'billing' => \App\Enum\BillingMode::STANDARD_WITH_DEPOSIT, 'gateway' => 'bank',
                'ext' => '113', 'in' => '2026-06-10', 'out' => '2026-06-14', 'name' => 'George Smith',
                'email' => 'g.smith@example.co.uk', 'phone' => '+44 7700 900123', 'street' => '21 Baker Street',
                'city' => 'London', 'zip' => 'NW16XE', 'country' => 'GB', 'adults' => 2, 'price' => '6800.00',
                'acq' => 'Google', 'vtKwh' => 38, 'ntKwh' => 24, 'clean' => [CleaningType::CLEANER, 700, 700],
                'inv' => 'deposit_only',
                'docs' => [
                    ['George', 'Smith', '1985-03-12', 'GBR', '512345678'],
                    ['Emily', 'Smith', '1988-07-30', 'GBR', '512345679'],
                ],
            ],

            // ── Potvrzené budoucí (CONFIRMED) ────────────────────────────────
            [
                'channel' => Channel::AIRBNB, 'billing' => \App\Enum\BillingMode::AIRBNB,
                'ext' => '114', 'in' => '2026-06-15', 'out' => '2026-06-18', 'name' => 'Filip Růžička',
                'region' => 'Liberec', 'adults' => 2, 'children' => 2, 'price' => '6000.00', 'acq' => 'Airbnb',
                'pet' => true, 'leadDays' => 28, 'clean' => [CleaningType::CLEANER, 700, 700], 'inv' => 'none',
            ],
            [
                'channel' => Channel::WEB, 'billing' => \App\Enum\BillingMode::STANDARD_WITH_DEPOSIT, 'gateway' => 'bank',
                'ext' => '115', 'in' => '2026-06-20', 'out' => '2026-06-24', 'name' => 'Kateřina Veselá',
                'email' => 'k.vesela@email.cz', 'phone' => '+420 608 444 555', 'street' => 'Zahradní 11',
                'city' => 'Strakonice', 'zip' => '38601', 'adults' => 2, 'children' => 1, 'price' => '6400.00',
                'acq' => 'Google', 'leadDays' => 30, 'clean' => [CleaningType::CLEANER, 700, 700], 'inv' => 'deposit_only',
            ],
            [
                'channel' => Channel::BOOKING, 'billing' => \App\Enum\BillingMode::BOOKING_COM,
                'ext' => '7000000206', 'in' => '2026-06-26', 'out' => '2026-06-29', 'name' => 'Lena Fischer',
                'street' => 'Bergstraße 4', 'city' => 'München', 'zip' => '80331', 'country' => 'DE',
                'adults' => 2, 'price' => '240.00', 'currency' => 'EUR', 'acq' => 'Booking.com', 'leadDays' => 25,
                'inv' => 'none',
            ],
            [
                'channel' => Channel::AIRBNB, 'billing' => \App\Enum\BillingMode::AIRBNB,
                'ext' => '116', 'in' => '2026-07-03', 'out' => '2026-07-06', 'name' => 'Simona Králová',
                'region' => 'Pardubice', 'adults' => 2, 'infants' => 1, 'price' => '4800.00', 'acq' => 'Airbnb',
                'cot' => true, 'leadDays' => 35, 'clean' => [CleaningType::CLEANER, 700, 700], 'inv' => 'none',
            ],
            [
                'channel' => Channel::WEB, 'billing' => \App\Enum\BillingMode::FKSP, 'gateway' => 'cash',
                'ext' => '117', 'in' => '2026-07-10', 'out' => '2026-07-13', 'name' => 'Magistrát města Plzně',
                'company' => 'Statutární město Plzeň', 'ico' => '00075370', 'dic' => 'CZ00075370',
                'email' => 'fksp@plzen.eu', 'street' => 'náměstí Republiky 1', 'city' => 'Plzeň', 'zip' => '30100',
                'adults' => 4, 'price' => '5400.00', 'acq' => 'doporučení', 'leadDays' => 40, 'inv' => 'none',
            ],
            [
                'channel' => Channel::WEB, 'billing' => \App\Enum\BillingMode::ADMIN_BOOKING, 'gateway' => 'manual',
                'ext' => '118', 'in' => '2026-07-18', 'out' => '2026-07-21', 'name' => 'Josef Zahradník',
                'email' => 'josef.z@email.cz', 'street' => 'Lesní 5', 'city' => 'Český Krumlov', 'zip' => '38101',
                'adults' => 2, 'children' => 2, 'price' => '3900.00', 'acq' => 'rodina', 'leadDays' => 30, 'inv' => 'none',
            ],

            // ── Čeká na doplnění údajů (NEEDS_DETAILS) ───────────────────────
            [
                'channel' => Channel::BOOKING, 'billing' => \App\Enum\BillingMode::BOOKING_COM, 'needsDetails' => true,
                'ext' => '7000000207', 'in' => '2026-07-24', 'name' => null, 'price' => null, 'leadDays' => 20, 'inv' => 'none',
            ],
            [
                'channel' => Channel::BOOKING, 'billing' => \App\Enum\BillingMode::BOOKING_COM, 'needsDetails' => true,
                'ext' => '7000000208', 'in' => '2026-08-02', 'name' => null, 'price' => null, 'leadDays' => 15, 'inv' => 'none',
            ],
        ];
    }
}
