<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Ical\Import;

use App\Connector\ConnectorManager;
use App\Entity\Reservation;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\ConnectorType;
use App\Enum\OwnerNotificationType;
use App\Enum\ReservationStatus;
use App\Notification\OwnerNotifier;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Import obsazenosti z OTA iCal feedu do rezervací. Feed je čistý blokátor
 * termínu — bere se z něj jen příjezd/odjezd a stabilní UID. Jméno ani cena se
 * z iCalu nikdy nečtou (stejná filozofie jako import bloků z MotoPressu).
 *
 * Upsert: rezervace se dohledá podle UID; když ještě UID nemá, adoptuje se
 * existující OTA blok téhož kanálu a příjezdu (typicky z MotoPressu) — tím se
 * zdroje slijí a nevznikne duplikát. Co v feedu bylo a zmizelo (a pobyt ještě
 * neskončil), se automaticky stornuje.
 */
final class IcalImporter
{
    public function __construct(
        private readonly IcalFeedFetcher $fetcher,
        private readonly IcalParser $parser,
        private readonly ConnectorManager $connectors,
        private readonly ReservationRepository $reservations,
        private readonly OwnerNotifier $notifier,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function import(ConnectorType $type, bool $dryRun = false): IcalImportResult
    {
        $channel = $type->icalChannel();
        if ($channel === null) {
            throw new \LogicException(sprintf('Konektor %s neumí iCal import.', $type->value));
        }
        $url = $this->connectors->getFeedUrl($type);
        if ($url === null) {
            throw new IcalFeedException('iCal feed URL není nastavená.');
        }

        $events = $this->parser->parse($this->fetcher->fetch($url));

        // Blokujeme od začátku aktuálního měsíce dál — minulé pobyty už neřešíme.
        $from = new \DateTimeImmutable('first day of this month 00:00');

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $seen = [];

        foreach ($events as $event) {
            $occupancyEnd = $event->end ?? $event->start->modify('+1 day');
            if ($occupancyEnd < $from) {
                continue;
            }
            $seen[$event->uid] = true;

            $reservation = $this->resolve($channel, $event);
            if ($reservation === null) {
                // Storno událost bez existující rezervace — není co zakládat.
                continue;
            }

            if ($reservation->getId() === null) {
                $this->applyEvent($reservation, $event, $channel, true);
                if (!$dryRun) {
                    $this->em->persist($reservation);
                    $this->notifier->notify(OwnerNotificationType::NEW_RESERVATION, $reservation);
                }
                $created++;
            } elseif ($this->applyEvent($reservation, $event, $channel, false)) {
                $updated++;
            } else {
                $unchanged++;
            }
        }

        $cancelled = $this->cancelVanished($channel, $from, $seen, $events);

        if (!$dryRun) {
            $this->em->flush();
        }

        return new IcalImportResult($created, $updated, $unchanged, $cancelled, count($events));
    }

    private function resolve(Channel $channel, IcalEvent $event): ?Reservation
    {
        $reservation = $this->reservations->findByIcalUid($event->uid)
            ?? $this->reservations->findAdoptableOtaBlock($channel, $event->start);

        // Storno bloku, který u nás nikdy nebyl, ignorujeme — nezakládáme rovnou zrušené.
        if ($reservation === null && $event->cancelled) {
            return null;
        }

        return $reservation ?? new Reservation($channel, $event->start);
    }

    /**
     * Promítne blok do rezervace (jen datumy + UID + default OTA mód). Vrací, zda
     * se něco změnilo — nezměněné běhy tak nechají rezervaci (i updatedAt) na pokoji.
     */
    private function applyEvent(Reservation $reservation, IcalEvent $event, Channel $channel, bool $isNew): bool
    {
        $changed = false;

        $status = $event->cancelled ? ReservationStatus::CANCELLED : ($isNew ? ReservationStatus::NEEDS_DETAILS : $reservation->getStatus());
        if ($reservation->getStatus() !== $status) {
            $reservation->setStatus($status);
            $changed = true;
        }
        if ($reservation->getCheckIn() != $event->start) {
            $reservation->setCheckIn($event->start);
            $changed = true;
        }
        if ($reservation->getCheckOut() != $event->end) {
            $reservation->setCheckOut($event->end);
            $changed = true;
        }
        if ($reservation->getIcalUid() !== $event->uid) {
            $reservation->setIcalUid($event->uid);
            $changed = true;
        }

        // Default fakturační mód jen když ho kanál zná a rezervace ho ještě nemá.
        $mode = $this->defaultBillingMode($channel);
        if ($mode !== null && $reservation->getBillingMode() === null) {
            $reservation->setBillingMode($mode);
            $changed = true;
        }

        return $changed;
    }

    private function defaultBillingMode(Channel $channel): ?BillingMode
    {
        return match ($channel) {
            Channel::BOOKING => BillingMode::BOOKING_COM,
            Channel::AIRBNB => BillingMode::AIRBNB,
            default => null,
        };
    }

    /**
     * Stornuje rezervace kanálu založené z feedu, jejichž UID v tomto běhu
     * nedorazilo (host zrušil na OTA). Bezpečnostní pojistka: při prázdném feedu
     * (0 událostí) nic neruší — nerozlišíme „žádné rezervace" od výpadku feedu.
     *
     * @param array<string, true> $seen
     * @param list<IcalEvent>     $events
     */
    private function cancelVanished(Channel $channel, \DateTimeImmutable $from, array $seen, array $events): int
    {
        if ($events === []) {
            $this->logger->warning('iCal feed bez událostí — přeskakuji detekci storn.', ['channel' => $channel->value]);

            return 0;
        }

        $cancelled = 0;
        foreach ($this->reservations->findActiveIcalReservations($channel, $from) as $reservation) {
            $uid = $reservation->getIcalUid();
            if ($uid !== null && !isset($seen[$uid])) {
                $reservation->setStatus(ReservationStatus::CANCELLED);
                $cancelled++;
            }
        }

        return $cancelled;
    }
}
