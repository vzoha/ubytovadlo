<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfigImportEnvCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SettingRepository $settings;
    private Application $application;

    /** @var list<string> */
    private array $touchedEnv = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->settings = $container->get(SettingRepository::class);

        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s')->execute();
        $this->application = new Application(self::$kernel);
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
        parent::tearDown();
    }

    public function testImportsSettingsFromEnv(): void
    {
        $this->setEnv('APP_BRAND_NAME', 'Penzion U Lesa');
        $this->setEnv('INVOICE_ISSUER_NAME', 'Jan Novák');
        $this->setEnv('INVOICE_DEPOSIT_AMOUNT', '1500');

        $this->runImport();

        self::assertSame('Penzion U Lesa', $this->settings->getString('app.brand_name'));
        self::assertSame('Jan Novák', $this->settings->getString('invoice.issuer.name'));
        self::assertSame('1500', $this->settings->getString('invoice.deposit.value'));
        self::assertSame('fixed', $this->settings->getString('invoice.deposit.mode'));
    }

    public function testDoesNotOverwriteExistingValueWithoutForce(): void
    {
        $this->settings->set('app.brand_name', 'Ruční název');
        $this->em->flush();
        $this->setEnv('APP_BRAND_NAME', 'Z env');

        $this->runImport();

        self::assertSame('Ruční název', $this->settings->getString('app.brand_name'));
    }

    public function testForceOverwritesExisting(): void
    {
        $this->settings->set('app.brand_name', 'Ruční název');
        $this->em->flush();
        $this->setEnv('APP_BRAND_NAME', 'Z env');

        $this->runImport(['--force' => true]);

        self::assertSame('Z env', $this->settings->getString('app.brand_name'));
    }

    public function testDryRunSavesNothing(): void
    {
        $this->setEnv('APP_BRAND_NAME', 'Penzion U Lesa');

        $this->runImport(['--dry-run' => true]);

        self::assertNull($this->settings->getString('app.brand_name'));
    }

    private function setEnv(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $this->touchedEnv[] = $name;
    }

    /** @param array<string, bool> $options */
    private function runImport(array $options = []): void
    {
        $tester = new CommandTester($this->application->find('app:config:import-env'));
        $tester->execute($options);
        $tester->assertCommandIsSuccessful();
    }
}
