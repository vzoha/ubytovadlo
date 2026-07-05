<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Connector;

use App\Connector\ConnectorHealth;
use App\Entity\Connector;
use App\Enum\ConnectorStatus;
use App\Enum\ConnectorType;
use PHPUnit\Framework\TestCase;

final class ConnectorHealthTest extends TestCase
{
    private const NOW = '2026-07-04 12:00:00';

    public function testDisabledWinsOverEverything(): void
    {
        $connector = $this->connector();
        $connector->setEnabled(false);
        $connector->recordRun(ConnectorStatus::ERROR, 'boom');

        self::assertSame(ConnectorHealth::STATE_DISABLED, $this->assess($connector, configured: true)->state);
    }

    public function testEnabledButUnconfiguredNeedsSetup(): void
    {
        self::assertSame(
            ConnectorHealth::STATE_NEEDS_SETUP,
            $this->assess($this->connector(), configured: false)->state,
        );
    }

    public function testErrorSurfacesLastError(): void
    {
        $connector = $this->connector();
        $connector->recordRun(ConnectorStatus::ERROR, 'IMAP down');

        $health = $this->assess($connector, configured: true);
        self::assertSame(ConnectorHealth::STATE_ERROR, $health->state);
        self::assertSame('IMAP down', $health->lastError);
    }

    public function testLongSilenceOnHealthyConnectorIsStale(): void
    {
        $connector = $this->connector();
        $connector->recordRun(ConnectorStatus::OK);
        $connector->recordActivity(new \DateTimeImmutable('2026-06-01 12:00:00')); // 33 dní zpět

        $health = $this->assess($connector, configured: true);
        self::assertSame(ConnectorHealth::STATE_STALE, $health->state);
        self::assertSame(33, $health->staleDays);
    }

    public function testRecentActivityIsOk(): void
    {
        $connector = $this->connector();
        $connector->recordRun(ConnectorStatus::OK);
        $connector->recordActivity(new \DateTimeImmutable('2026-07-03 12:00:00'));

        self::assertSame(ConnectorHealth::STATE_OK, $this->assess($connector, configured: true)->state);
    }

    public function testFutureActivityFromSkewedEmailIsNotStale(): void
    {
        $connector = $this->connector();
        $connector->recordRun(ConnectorStatus::OK);
        $connector->recordActivity(new \DateTimeImmutable('2027-01-01 12:00:00')); // datum v budoucnu

        $health = $this->assess($connector, configured: true);
        self::assertSame(0, $health->staleDays);
        self::assertSame(ConnectorHealth::STATE_OK, $health->state);
    }

    public function testConfiguredButNeverRunIsIdle(): void
    {
        $health = $this->assess($this->connector(), configured: true);
        self::assertSame(ConnectorHealth::STATE_IDLE, $health->state);
        self::assertNull($health->staleDays);
    }

    private function assess(Connector $connector, bool $configured): ConnectorHealth
    {
        return ConnectorHealth::assess($connector, $configured, new \DateTimeImmutable(self::NOW));
    }

    private function connector(): Connector
    {
        return new Connector(ConnectorType::BOOKING);
    }
}
