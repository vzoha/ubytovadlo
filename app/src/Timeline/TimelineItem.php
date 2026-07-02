<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Timeline;

use App\Entity\ReservationAction;
use App\Entity\ReservationNote;
use App\Enum\ActionStatus;

/**
 * Jedna položka na časové ose rezervace. Sjednocuje tři přírody:
 *  - 'event' — odvozená systémová událost (neukládá se)
 *  - 'note'  — ruční CRM poznámka (ReservationNote)
 *  - 'action'— naplánovaná akce (ReservationAction), v UI s tlačítky
 */
final readonly class TimelineItem
{
    /**
     * @param 'event'|'note'|'action' $kind
     */
    private function __construct(
        public \DateTimeImmutable $at,
        public string $kind,
        public string $icon,
        public string $title,
        public ?string $body = null,
        public ?string $meta = null,
        public ?ReservationAction $action = null,
        public ?ActionStatus $status = null,
        public bool $dateOnly = false,
    ) {
    }

    /**
     * @param bool $dateOnly událost nese jen datum (bez smysluplného času, např. faktura) → v UI skrýt čas
     */
    public static function event(\DateTimeImmutable $at, string $icon, string $title, ?string $meta = null, bool $dateOnly = false): self
    {
        return new self($at, 'event', $icon, $title, null, $meta, dateOnly: $dateOnly);
    }

    public static function fromNote(ReservationNote $note): self
    {
        $author = $note->getAuthor()?->getUserIdentifier();

        return new self(
            $note->getOccurredAt(),
            'note',
            $note->getType()->icon(),
            $note->getType()->label(),
            $note->getBody(),
            $author,
        );
    }

    public static function fromAction(ReservationAction $action): self
    {
        return new self(
            $action->getScheduledFor(),
            'action',
            $action->getType()->icon(),
            $action->getType()->label(),
            $action->getLabel() !== $action->getType()->label() ? $action->getLabel() : null,
            $action->getOrigin()->label(),
            $action,
            $action->getStatus(),
        );
    }

    /** Akce, která je stále otevřená (PLANNED) → v UI nabídnout odložit/zrušit/spustit. */
    public function isOpenAction(): bool
    {
        return $this->kind === 'action' && $this->status === ActionStatus::PLANNED;
    }
}
