<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Enum\OwnerNotificationType;
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
 * Cron (denně) — kolem 20. dne v měsíci připomene DPH přiznání za předchozí
 * měsíc, pokud v něm byla přijatá provize od OTA (reverse charge). Do fronty
 * založí jednu notifikaci; odešle ji dispatch/digest podle nastaveného režimu.
 * Idempotentní přes Setting `notifications.vat_reminder.last_period`.
 */
#[AsCommand(name: 'app:vat:remind', description: 'Připomene DPH přiznání za měsíc s přijatou provizí.')]
final class VatRemindCommand extends Command
{
    private const LAST_PERIOD_KEY = 'notifications.vat_reminder.last_period';
    private const DAY_THRESHOLD = 20;

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
        $this
            ->addOption('month', null, InputOption::VALUE_REQUIRED, 'Cílový měsíc YYYY-MM (jinak předchozí měsíc).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignoruj práh dne v měsíci i už odeslanou připomínku.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $force = (bool) $input->getOption('force');

        if (!$this->taxProfile->current()->reverseChargesCommission()) {
            $io->note('Daňový profil neplátce — reverse charge z provize nevzniká, připomínka není potřeba.');

            return Command::SUCCESS;
        }

        if ($this->settings->recipient() === null) {
            $io->warning('Není nastavena adresa příjemce notifikací — přeskočeno.');

            return Command::SUCCESS;
        }

        if (!$force && (int) $now->format('j') < self::DAY_THRESHOLD) {
            $io->note(sprintf('Ještě není %d. den v měsíci — přeskočeno.', self::DAY_THRESHOLD));

            return Command::SUCCESS;
        }

        $period = $this->resolvePeriod($input, $now);
        [$year, $month] = array_map('intval', explode('-', $period));

        if (!$force && $this->settingRepository->getString(self::LAST_PERIOD_KEY) === $period) {
            $io->note(sprintf('Připomínka za %s už byla odeslána — přeskočeno.', $period));

            return Command::SUCCESS;
        }

        if ($this->reservations->findCommissionableByCheckoutMonth($year, $month) === []) {
            $io->success(sprintf('Za %s žádná přijatá provize — připomínka není potřeba.', $period));

            return Command::SUCCESS;
        }

        $this->notifier->notify(OwnerNotificationType::VAT_REMINDER, null, ['period' => $period]);
        $this->settingRepository->set(self::LAST_PERIOD_KEY, $period, 'Poslední odeslaná DPH připomínka.');
        $this->em->flush();

        $io->success(sprintf('Připomínka DPH za %s zařazena do fronty.', $period));

        return Command::SUCCESS;
    }

    private function resolvePeriod(InputInterface $input, \DateTimeImmutable $now): string
    {
        $month = $input->getOption('month');
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return $month;
        }

        return $now->modify('first day of previous month')->format('Y-m');
    }
}
