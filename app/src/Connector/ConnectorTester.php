<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Connector;

use App\Email\ImapClientFactory;
use App\Enum\ConnectorType;
use App\MotoPress\MotoPressClient;

/**
 * Ověří, že konektor umí navázat spojení: MotoPress zavolá REST, e-mailové
 * konektory (Booking/Airbnb/banka) se přihlásí do sdílené automatizační schránky.
 * Test nemění data — jen řekne, jestli přístupy fungují.
 */
final class ConnectorTester
{
    public function __construct(
        private readonly ConnectorManager $manager,
        private readonly ImapClientFactory $imap,
        private readonly MotoPressClient $motopress,
    ) {
    }

    public function test(ConnectorType $type): ConnectorTestResult
    {
        if (!$this->manager->isConfigured($type)) {
            return ConnectorTestResult::failure('Chybí přístupové údaje — nejdřív je vyplňte.');
        }

        try {
            return $type === ConnectorType::MOTOPRESS
                ? $this->testMotoPress()
                : $this->testImap();
        } catch (\Throwable $e) {
            return ConnectorTestResult::failure($e->getMessage());
        }
    }

    private function testMotoPress(): ConnectorTestResult
    {
        $this->motopress->listServices();

        return ConnectorTestResult::success('Spojení s webem (MotoPress REST) funguje.');
    }

    private function testImap(): ConnectorTestResult
    {
        $client = $this->imap->connect();
        $client->disconnect();

        return ConnectorTestResult::success('Přihlášení do automatizační schránky (IMAP) funguje.');
    }
}
