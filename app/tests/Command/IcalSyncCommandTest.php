<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Command;

use App\Connector\ConnectorManager;
use App\Entity\Connector;
use App\Entity\Reservation;
use App\Enum\ConnectorStatus;
use App\Enum\ConnectorType;
use App\Repository\ConnectorRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IcalSyncCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ConnectorManager $connectors;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connectors = $container->get(ConnectorManager::class);

        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . Connector::class . ' c')->execute();
        $this->em->flush();

        $this->application = new Application(self::$kernel);
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->application->find('app:ical:sync'));
    }

    private function mockHttp(MockResponse $response): void
    {
        static::getContainer()->set('http_client', new MockHttpClient($response));
    }

    private function futureEventIcs(string $uid): string
    {
        $base = (new \DateTimeImmutable('first day of next month'))->setTime(0, 0);
        $start = $base->modify('+10 days')->format('Ymd');
        $end = $base->modify('+15 days')->format('Ymd');

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTART;VALUE=DATE:{$start}\r\nDTEND;VALUE=DATE:{$end}\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }

    private function connector(ConnectorType $type): ?Connector
    {
        return static::getContainer()->get(ConnectorRepository::class)->findOneBy(['type' => $type]);
    }

    public function testSkipsDisabledAndFeedlessConnectors(): void
    {
        // Booking vypnutý, ostatní bez feedu → žádný síťový požadavek, běh projde.
        $this->connectors->setEnabled(ConnectorType::BOOKING, false);

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        $output = $tester->getDisplay();
        self::assertStringContainsString('vypnuto', $output);
        self::assertStringContainsString('bez feedu', $output);
    }

    public function testListsAllIcalConnectors(): void
    {
        $tester = $this->tester();
        $tester->execute([]);
        $output = $tester->getDisplay();

        foreach (ConnectorType::icalConnectors() as $type) {
            self::assertStringContainsString($type->label(), $output);
        }
    }

    public function testImportsFeedAndRecordsActivity(): void
    {
        $this->connectors->setFeedUrl(ConnectorType::BOOKING, 'https://example.test/feed.ics');
        $this->mockHttp(new MockResponse($this->futureEventIcs('a@booking.com')));

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('ok (1 událostí)', $tester->getDisplay());
        self::assertNotNull(static::getContainer()->get(ReservationRepository::class)->findByIcalUid('a@booking.com'));

        $this->em->clear();
        $connector = $this->connector(ConnectorType::BOOKING);
        self::assertNotNull($connector);
        self::assertSame(ConnectorStatus::OK, $connector->getLastStatus());
        self::assertNotNull($connector->getLastActivityAt());
    }

    public function testFeedFailureRecordsError(): void
    {
        $this->connectors->setFeedUrl(ConnectorType::BOOKING, 'https://example.test/feed.ics');
        $this->mockHttp(new MockResponse('nope', ['http_code' => 500]));

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('chyba', $tester->getDisplay());

        $this->em->clear();
        self::assertSame(ConnectorStatus::ERROR, $this->connector(ConnectorType::BOOKING)?->getLastStatus());
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->connectors->setFeedUrl(ConnectorType::BOOKING, 'https://example.test/feed.ics');
        $this->mockHttp(new MockResponse($this->futureEventIcs('dry@booking.com')));

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        self::assertNull(static::getContainer()->get(ReservationRepository::class)->findByIcalUid('dry@booking.com'));
    }
}
