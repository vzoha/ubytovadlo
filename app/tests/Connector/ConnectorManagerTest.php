<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Connector;

use App\Connector\ConnectorManager;
use App\Entity\Connector;
use App\Enum\ConnectorStatus;
use App\Enum\ConnectorType;
use App\Repository\ConnectorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConnectorManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ConnectorManager $manager;
    private ConnectorRepository $connectors;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->manager = $container->get(ConnectorManager::class);
        $this->connectors = $container->get(ConnectorRepository::class);

        $this->em->createQuery('DELETE FROM ' . Connector::class . ' c')->execute();
    }

    public function testEnabledByDefaultWithoutRow(): void
    {
        self::assertTrue($this->manager->isEnabled(ConnectorType::AIRBNB));
    }

    public function testSetEnabledPersists(): void
    {
        $this->manager->setEnabled(ConnectorType::AIRBNB, false);
        $this->em->clear();

        self::assertFalse($this->manager->isEnabled(ConnectorType::AIRBNB));
    }

    public function testRecordRunStoresStatusAndClearsErrorOnSuccess(): void
    {
        $this->manager->recordRun(ConnectorType::BOOKING, ConnectorStatus::ERROR, 'nedostupné');
        $this->em->clear();
        self::assertSame(ConnectorStatus::ERROR, $this->reload(ConnectorType::BOOKING)->getLastStatus());
        self::assertSame('nedostupné', $this->reload(ConnectorType::BOOKING)->getLastError());

        $this->manager->recordRun(ConnectorType::BOOKING, ConnectorStatus::OK);
        $this->em->clear();
        self::assertSame(ConnectorStatus::OK, $this->reload(ConnectorType::BOOKING)->getLastStatus());
        self::assertNull($this->reload(ConnectorType::BOOKING)->getLastError());
    }

    public function testRecordActivityOnlyMovesForward(): void
    {
        $this->manager->recordActivity(ConnectorType::BANK_CS, new \DateTimeImmutable('2026-07-01 10:00:00'));
        // Starší značka nesmí přebít novější.
        $this->manager->recordActivity(ConnectorType::BANK_CS, new \DateTimeImmutable('2026-06-20 10:00:00'));
        $this->manager->recordRun(ConnectorType::BANK_CS, ConnectorStatus::OK); // flush
        $this->em->clear();

        self::assertEquals(
            new \DateTimeImmutable('2026-07-01 10:00:00'),
            $this->reload(ConnectorType::BANK_CS)->getLastActivityAt(),
        );
    }

    public function testHealthReturnsAllTypesInEnumOrder(): void
    {
        $health = $this->manager->health();

        self::assertSame(
            [
                ConnectorType::MOTOPRESS,
                ConnectorType::BOOKING,
                ConnectorType::AIRBNB,
                ConnectorType::ECHALUPY,
                ConnectorType::CS_CHALUPY,
                ConnectorType::BANK_CS,
            ],
            array_map(static fn ($h) => $h->type, $health),
        );
    }

    private function reload(ConnectorType $type): Connector
    {
        $connector = $this->connectors->findOneBy(['type' => $type]);
        self::assertInstanceOf(Connector::class, $connector);

        return $connector;
    }
}
