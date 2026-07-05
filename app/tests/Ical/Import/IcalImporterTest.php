<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Ical\Import;

use App\Connector\ConnectorManager;
use App\Entity\Connector;
use App\Entity\PendingOwnerNotification;
use App\Entity\Reservation;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\ConnectorType;
use App\Enum\ReservationStatus;
use App\Ical\Import\IcalFeedFetcher;
use App\Ical\Import\IcalImporter;
use App\Ical\Import\IcalImportResult;
use App\Ical\Import\IcalParser;
use App\Notification\OwnerNotifier;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IcalImporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ConnectorManager $connectors;
    private ReservationRepository $reservations;
    private OwnerNotifier $notifier;

    private \DateTimeImmutable $start;
    private \DateTimeImmutable $end;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connectors = $container->get(ConnectorManager::class);
        $this->reservations = $container->get(ReservationRepository::class);
        $this->notifier = $container->get(OwnerNotifier::class);

        $this->em->createQuery('DELETE FROM ' . PendingOwnerNotification::class . ' n')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . Connector::class . ' c')->execute();
        $this->em->flush();

        // Vždy v budoucnu, ať pobyt projde filtrem „od začátku aktuálního měsíce".
        $base = (new \DateTimeImmutable('first day of next month'))->setTime(0, 0);
        $this->start = $base->modify('+10 days');
        $this->end = $base->modify('+15 days');
    }

    private function importer(string $ics): IcalImporter
    {
        $fetcher = new IcalFeedFetcher(new MockHttpClient(new MockResponse($ics)), new NullLogger());

        return new IcalImporter(
            $fetcher,
            new IcalParser(),
            $this->connectors,
            $this->reservations,
            $this->notifier,
            $this->em,
            new NullLogger(),
        );
    }

    private function importFeed(string $ics, ConnectorType $type = ConnectorType::BOOKING): IcalImportResult
    {
        $this->connectors->setFeedUrl($type, 'https://example.test/feed.ics');
        $result = $this->importer($ics)->import($type);
        $this->em->clear();

        return $result;
    }

    private function ics(string ...$events): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n" . implode("\r\n", $events) . "\r\nEND:VCALENDAR\r\n";
    }

    private function event(string $uid, \DateTimeImmutable $start, ?\DateTimeImmutable $end = null, string $status = ''): string
    {
        $lines = ['BEGIN:VEVENT', 'UID:' . $uid, 'DTSTART;VALUE=DATE:' . $start->format('Ymd')];
        if ($end !== null) {
            $lines[] = 'DTEND;VALUE=DATE:' . $end->format('Ymd');
        }
        if ($status !== '') {
            $lines[] = 'STATUS:' . $status;
        }
        $lines[] = 'END:VEVENT';

        return implode("\r\n", $lines);
    }

    public function testCreatesOccupancyBlockWithoutGuestOrPrice(): void
    {
        $result = $this->importFeed($this->ics($this->event('a@booking.com', $this->start, $this->end)));

        self::assertSame(1, $result->created);
        $reservation = $this->reservations->findByIcalUid('a@booking.com');
        self::assertNotNull($reservation);
        self::assertSame(Channel::BOOKING, $reservation->getChannel());
        self::assertSame(ReservationStatus::NEEDS_DETAILS, $reservation->getStatus());
        self::assertSame($this->start->format('Y-m-d'), $reservation->getCheckIn()->format('Y-m-d'));
        self::assertSame($this->end->format('Y-m-d'), $reservation->getCheckOut()?->format('Y-m-d'));
        self::assertSame(BillingMode::BOOKING_COM, $reservation->getBillingMode());
        self::assertNull($reservation->getGuestName());
        self::assertNull($reservation->getPriceTotal());
    }

    public function testSecondRunIsUnchanged(): void
    {
        $ics = $this->ics($this->event('a@booking.com', $this->start, $this->end));
        $this->importFeed($ics);
        $result = $this->importFeed($ics);

        self::assertSame(0, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(1, $result->unchanged);
    }

    public function testUpdatesCheckoutWhenChanged(): void
    {
        $this->importFeed($this->ics($this->event('a@booking.com', $this->start, $this->end)));
        $result = $this->importFeed($this->ics($this->event('a@booking.com', $this->start, $this->end->modify('+1 day'))));

        self::assertSame(1, $result->updated);
        self::assertSame(
            $this->end->modify('+1 day')->format('Y-m-d'),
            $this->reservations->findByIcalUid('a@booking.com')?->getCheckOut()?->format('Y-m-d'),
        );
    }

    public function testAdoptsExistingBlockOnSameDate(): void
    {
        // Blok „už importovaný z MotoPressu" — stejný kanál a příjezd, bez UID.
        $existing = new Reservation(Channel::BOOKING, $this->start);
        $existing->setCheckOut($this->end);
        $this->em->persist($existing);
        $this->em->flush();
        $this->em->clear();

        $result = $this->importFeed($this->ics($this->event('a@booking.com', $this->start, $this->end)));

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->updated);
        self::assertSame(1, $this->reservations->count([]));
        self::assertSame('a@booking.com', $this->reservations->findAll()[0]->getIcalUid());
    }

    public function testCancelsVanishedReservation(): void
    {
        $this->importFeed($this->ics(
            $this->event('a@booking.com', $this->start, $this->end),
            $this->event('b@booking.com', $this->start->modify('+30 days'), $this->end->modify('+30 days')),
        ));

        // Druhý běh bez 'a' (ale s 'b', ať feed není prázdný) → 'a' se stornuje.
        $result = $this->importFeed($this->ics(
            $this->event('b@booking.com', $this->start->modify('+30 days'), $this->end->modify('+30 days')),
        ));

        self::assertSame(1, $result->cancelled);
        self::assertSame(ReservationStatus::CANCELLED, $this->reservations->findByIcalUid('a@booking.com')?->getStatus());
        self::assertSame(ReservationStatus::NEEDS_DETAILS, $this->reservations->findByIcalUid('b@booking.com')?->getStatus());
    }

    public function testEmptyFeedDoesNotCancel(): void
    {
        $this->importFeed($this->ics($this->event('a@booking.com', $this->start, $this->end)));
        $result = $this->importFeed($this->ics());

        self::assertSame(0, $result->cancelled);
        self::assertSame(ReservationStatus::NEEDS_DETAILS, $this->reservations->findByIcalUid('a@booking.com')?->getStatus());
    }

    public function testCancelledStatusEventCancelsExisting(): void
    {
        $this->importFeed($this->ics($this->event('a@booking.com', $this->start, $this->end)));
        $this->importFeed($this->ics($this->event('a@booking.com', $this->start, $this->end, 'CANCELLED')));

        self::assertSame(ReservationStatus::CANCELLED, $this->reservations->findByIcalUid('a@booking.com')?->getStatus());
    }

    public function testDoesNotCancelNonIcalReservation(): void
    {
        $web = new Reservation(Channel::WEB, $this->start);
        $web->setStatus(ReservationStatus::CONFIRMED);
        $this->em->persist($web);
        $this->em->flush();
        $this->em->clear();

        // Import feedu jiného kanálu (Airbnb) nesmí sáhnout na web rezervaci.
        $this->importFeed($this->ics($this->event('x@airbnb.com', $this->start, $this->end)), ConnectorType::AIRBNB);

        self::assertSame(ReservationStatus::CONFIRMED, $this->reservations->findByChannelAndCheckIn(Channel::WEB, $this->start)?->getStatus());
    }
}
