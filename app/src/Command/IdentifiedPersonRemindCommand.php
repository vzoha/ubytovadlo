<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\Reservation;
use App\Enum\OwnerNotificationType;
use App\Enum\TaxProfile;
use App\Invoice\TaxProfileConfig;
use App\Notification\OwnerNotificationSettingsProvider;
use App\Notification\OwnerNotifier;
use App\Repository\ReservationRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron (denně) — jakmile neplátci dorazí první provize z OTA (přeshraniční přijatá
 * služba z EU), založí mu registrační povinnost identifikované osoby (§6h ZDPH) s
 * lhůtou 15 dnů. Pošle o tom jednu notifikaci. Jednorázové přes Setting
 * `notifications.identified_person.notified_at`; jakmile si profil přepne na
 * identifikovanou osobu / plátce, je registrovaný a připomínka se nepouští.
 */
#[AsCommand(name: 'app:tax:identified-person-remind', description: 'Upozorní na vznik identifikované osoby a registrační lhůtu 15 dnů.')]
final class IdentifiedPersonRemindCommand extends Command
{
    private const NOTIFIED_KEY = 'notifications.identified_person.notified_at';
    private const REGISTRATION_DAYS = 15;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly OwnerNotifier $notifier,
        private readonly OwnerNotificationSettingsProvider $settings,
        private readonly SettingRepository $settingRepository,
        private readonly TaxProfileConfig $taxProfile,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ignoruj už odeslané upozornění.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        // Onset registrační povinnosti řešíme jen u neplátce — kdo už je identifikovaná
        // osoba nebo plátce, je registrovaný a připomínka nemá smysl.
        if ($this->taxProfile->current() !== TaxProfile::NON_PAYER) {
            $io->note('Daňový profil není neplátce — registrační povinnost identifikované osoby už je vyřešená.');

            return Command::SUCCESS;
        }

        if ($this->settings->recipient() === null) {
            $io->warning('Není nastavena adresa příjemce notifikací — přeskočeno.');

            return Command::SUCCESS;
        }

        if (!$force && $this->settingRepository->getString(self::NOTIFIED_KEY) !== null) {
            $io->note('Na vznik identifikované osoby už bylo upozorněno — přeskočeno.');

            return Command::SUCCESS;
        }

        $commissioned = $this->reservations->findAllWithCommission();
        if ($commissioned === []) {
            $io->success('Žádná přijatá provize z OTA — registrační povinnost zatím nevzniká.');

            return Command::SUCCESS;
        }

        $onset = $this->earliestServiceDate($commissioned);
        $deadline = $onset->modify('+' . self::REGISTRATION_DAYS . ' days');

        $this->notifier->notify(OwnerNotificationType::IDENTIFIED_PERSON_ONSET, null, [
            'since' => $onset->format('j. n. Y'),
            'deadline' => $deadline->format('j. n. Y'),
        ]);
        $this->settingRepository->set(
            self::NOTIFIED_KEY,
            (new \DateTimeImmutable())->format('Y-m-d'),
            'Datum upozornění na vznik identifikované osoby.',
        );
        $this->em->flush();

        $io->success('Upozornění na vznik identifikované osoby zařazeno do fronty.');

        return Command::SUCCESS;
    }

    /**
     * Datum poskytnutí první přijaté služby (den odjezdu, jinak příjezdu) — od něj běží
     * 15denní registrační lhůta.
     *
     * @param Reservation[] $reservations
     */
    private function earliestServiceDate(array $reservations): \DateTimeImmutable
    {
        $earliest = null;
        foreach ($reservations as $reservation) {
            $date = $reservation->getCheckOut() ?? $reservation->getCheckIn();
            if ($earliest === null || $date < $earliest) {
                $earliest = $date;
            }
        }
        \assert($earliest !== null);

        return $earliest;
    }
}
