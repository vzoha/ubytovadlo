<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Credential\CredentialCipher;
use App\Invoice\DepositConfig;
use App\Repository\CredentialRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Jednorázová migrace konfigurace z prostředí (.env.local) do databáze pro instance
 * založené dřív, než se veškerá konfigurace přesunula do UI. Přečte dosavadní env
 * proměnné a uloží je jako settings (identita, fakturace, chování) a šifrované
 * přístupy (IMAP, MotoPress). Nepřepisuje už nastavené hodnoty (leda s --force).
 * Po doběhnutí lze tyhle proměnné z .env.local smazat.
 */
#[AsCommand(name: 'app:config:import-env', description: 'Přenese konfiguraci z env (.env.local) do databáze (jednorázová migrace).')]
final class ConfigImportEnvCommand extends Command
{
    /** env proměnná => klíč settingu (netajné, plaintext). */
    private const SETTING_MAP = [
        'APP_BRAND_NAME' => 'app.brand_name',
        'DEFAULT_URI' => 'app.base_url',
        'INVOICE_ISSUER_NAME' => 'invoice.issuer.name',
        'INVOICE_ISSUER_STREET' => 'invoice.issuer.street',
        'INVOICE_ISSUER_CITY' => 'invoice.issuer.city',
        'INVOICE_ISSUER_ZIP' => 'invoice.issuer.zip',
        'INVOICE_ISSUER_COUNTRY' => 'invoice.issuer.country',
        'INVOICE_ISSUER_ICO' => 'invoice.issuer.ico',
        'INVOICE_ISSUER_DIC' => 'invoice.issuer.dic',
        'INVOICE_ISSUER_PHONE' => 'invoice.issuer.phone',
        'INVOICE_ISSUER_EMAIL' => 'invoice.issuer.email',
        'INVOICE_ISSUER_WEB' => 'invoice.issuer.web',
        'INVOICE_BANK_ACCOUNT' => 'invoice.bank.account',
        'INVOICE_BANK_IBAN' => 'invoice.bank.iban',
        'MOTOPRESS_PET_SERVICE_IDS' => 'motopress.pet_service_ids',
        'MOTOPRESS_BABY_COT_SERVICE_IDS' => 'motopress.baby_cot_service_ids',
    ];

    /** env proměnná => klíč šifrovaného přístupu (credential store). */
    private const CREDENTIAL_MAP = [
        'IMAP_HOST' => 'imap.host',
        'IMAP_PORT' => 'imap.port',
        'IMAP_ENCRYPTION' => 'imap.encryption',
        'IMAP_USERNAME' => 'imap.username',
        'IMAP_PASSWORD' => 'imap.password',
        'IMAP_FOLDER' => 'imap.folder',
        'MOTOPRESS_BASE_URL' => 'motopress.base_url',
        'MOTOPRESS_CONSUMER_KEY' => 'motopress.consumer_key',
        'MOTOPRESS_CONSUMER_SECRET' => 'motopress.consumer_secret',
    ];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly CredentialRepository $credentials,
        private readonly CredentialCipher $cipher,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen vypíše, co by přenesl, nic neuloží.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Přepíše i hodnoty, které už v DB jsou.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        $imported = $skipped = 0;

        foreach (self::SETTING_MAP as $envName => $key) {
            $value = $this->env($envName);
            if ($value === '') {
                continue;
            }
            [$imported, $skipped] = $this->applySetting($io, $key, $value, $force, $dryRun, $imported, $skipped);
        }

        // Zvláštní případy: záloha (+ režim fixní), číselná řada (jen neprázdná mapa), push plateb.
        $deposit = $this->env('INVOICE_DEPOSIT_AMOUNT');
        if ($deposit !== '' && is_numeric($deposit) && (float) $deposit > 0) {
            [$imported, $skipped] = $this->applySetting($io, DepositConfig::KEY_VALUE, $deposit, $force, $dryRun, $imported, $skipped);
            [$imported, $skipped] = $this->applySetting($io, DepositConfig::KEY_MODE, 'fixed', $force, $dryRun, $imported, $skipped);
        }
        $series = $this->env('INVOICE_SERIES_STARTS');
        if ($series !== '' && $series !== '{}' && $series !== '[]') {
            [$imported, $skipped] = $this->applySetting($io, 'invoice.series_starts', $series, $force, $dryRun, $imported, $skipped);
        }
        if ($this->env('MOTOPRESS_PUSH_PAYMENTS') === '1') {
            [$imported, $skipped] = $this->applySetting($io, 'motopress.push_payments', '1', $force, $dryRun, $imported, $skipped);
        }

        // Šifrované přístupy — jen když je master klíč (APP_CREDENTIALS_KEY) k dispozici.
        $hasCredEnv = false;
        foreach (array_keys(self::CREDENTIAL_MAP) as $envName) {
            if ($this->env($envName) !== '') {
                $hasCredEnv = true;
                break;
            }
        }
        if ($hasCredEnv && !$this->cipher->isReady()) {
            $io->warning('APP_CREDENTIALS_KEY není nastaven — přístupové údaje (IMAP/MotoPress) se nepřenesly. Doplň klíč a spusť znovu.');
        } elseif ($hasCredEnv) {
            foreach (self::CREDENTIAL_MAP as $envName => $key) {
                $value = $this->env($envName);
                if ($value === '') {
                    continue;
                }
                [$imported, $skipped] = $this->applyCredential($io, $key, $value, $force, $dryRun, $imported, $skipped);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf('%s Přeneseno %d, přeskočeno %d.', $dryRun ? '[DRY RUN]' : 'Hotovo.', $imported, $skipped));
        if ($imported > 0 && !$dryRun) {
            $io->note('Přenesené proměnné teď můžeš z .env.local smazat — čtou se z databáze.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{int, int} aktualizované počty [imported, skipped]
     */
    private function applySetting(SymfonyStyle $io, string $key, string $value, bool $force, bool $dryRun, int $imported, int $skipped): array
    {
        if (!$force && $this->settings->getString($key) !== null) {
            $io->writeln(sprintf('  <fg=yellow>·</> %s — už v DB, přeskočeno', $key));

            return [$imported, $skipped + 1];
        }
        $io->writeln(sprintf('  <fg=green>+</> %s = %s', $key, $value));
        if (!$dryRun) {
            $this->settings->set($key, $value, 'Přeneseno z env.');
        }

        return [$imported + 1, $skipped];
    }

    /**
     * @return array{int, int} aktualizované počty [imported, skipped]
     */
    private function applyCredential(SymfonyStyle $io, string $key, string $value, bool $force, bool $dryRun, int $imported, int $skipped): array
    {
        if (!$force && $this->credentials->getDecrypted($key) !== null) {
            $io->writeln(sprintf('  <fg=yellow>·</> %s — už v DB, přeskočeno', $key));

            return [$imported, $skipped + 1];
        }
        $io->writeln(sprintf('  <fg=green>+</> %s (šifrovaně)', $key));
        if (!$dryRun) {
            $this->credentials->setEncrypted($key, $value);
        }

        return [$imported + 1, $skipped];
    }

    private function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
