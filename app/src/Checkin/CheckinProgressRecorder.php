<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Checkin;

use App\Entity\CheckinEvent;
use App\Entity\Reservation;
use App\Enum\CheckinDevice;
use App\Enum\CheckinStep;
use App\Enum\DocumentReadSource;
use App\Mrz\MrzResult;
use App\Repository\CheckinEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Zapisuje kroky hosta v online check-inu, ze kterých majitel na časové ose
 * vyčte, jak vyplňování probíhalo. Přihlášený uživatel (majitel, který si
 * check-in prohlíží) se nezapisuje — průběh patří hostovi.
 */
class CheckinProgressRecorder
{
    /** Návrat po delší pauze je nová návštěva; přesměrování po uložení ne. */
    private const int VISIT_GAP_SECONDS = 1800;

    /** Strop proti zahlcení tabulky (živá kamera čte snímek po snímku). */
    private const int MAX_EVENTS = 500;

    public function __construct(
        private readonly CheckinEventRepository $events,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Prohlížení už dokončeného check-inu se nezapisuje. */
    public function visit(Reservation $reservation, Request $request): void
    {
        if ($reservation->getCheckinCompletedAt() !== null) {
            return;
        }
        $last = $this->events->lastOccurredAt($reservation);
        if ($last !== null && $this->clock->now()->getTimestamp() - $last->getTimestamp() < self::VISIT_GAP_SECONDS) {
            return;
        }

        $device = CheckinDevice::fromUserAgent((string) $request->headers->get('User-Agent'));
        $this->record($reservation, CheckinStep::VISIT, ['device' => $device->value]);
    }

    /**
     * @param MrzResult|null $result null = text se nepodařilo rozpoznat
     */
    public function documentRead(Reservation $reservation, ?DocumentReadSource $source, ?MrzResult $result): void
    {
        $this->record($reservation, CheckinStep::DOCUMENT_READ, [
            'source' => $source?->value,
            'ok' => $result !== null,
            'confidence' => $result?->confidence,
        ]);
    }

    /** Uložený host; prohlížeč v `checkin_prefill` hlásí, odkud formulář předvyplnil. */
    public function hostSaved(Reservation $reservation, bool $isNew, Request $request): void
    {
        $this->record($reservation, CheckinStep::HOST_SAVED, [
            'new' => $isNew,
            'prefill' => DocumentReadSource::fromInput($request->request->get('checkin_prefill'))?->value,
        ]);
    }

    public function hostDeleted(Reservation $reservation): void
    {
        $this->record($reservation, CheckinStep::HOST_DELETED);
    }

    public function billingSaved(Reservation $reservation): void
    {
        $this->record($reservation, CheckinStep::BILLING_SAVED);
    }

    /**
     * Zobrazený formulář hosta: otevření, nebo neplatné odeslání s názvy polí,
     * která neprošla — bez hodnot. Chyba celého formuláře (třeba CSRF) jde pod `_form`.
     *
     * @param FormInterface<mixed> $form
     */
    public function hostForm(Reservation $reservation, FormInterface $form, bool $isNew): void
    {
        if (!$form->isSubmitted()) {
            $this->record($reservation, CheckinStep::HOST_FORM, ['new' => $isNew]);

            return;
        }

        $fields = $form->getErrors()->count() > 0 ? ['_form'] : [];
        foreach ($form->all() as $name => $child) {
            if ($child->getErrors(true)->count() > 0) {
                $fields[] = (string) $name;
            }
        }
        $this->record($reservation, CheckinStep::HOST_INVALID, ['fields' => $fields]);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(Reservation $reservation, CheckinStep $step, array $details = []): void
    {
        if ($this->security->getUser() !== null || $this->events->countForReservation($reservation) >= self::MAX_EVENTS) {
            return;
        }

        $this->em->persist(new CheckinEvent($reservation, $step, $details, $this->clock->now()));
        $this->em->flush();
    }
}
