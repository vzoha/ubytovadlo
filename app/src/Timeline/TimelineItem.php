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
use App\Enum\ActionDelivery;
use App\Enum\ActionStatus;
use App\Enum\MessageOutlook;

/**
 * Jedna položka na časové ose rezervace. Sjednocuje tři přírody:
 *  - 'event' — odvozená systémová událost (neukládá se)
 *  - 'note'  — ruční CRM poznámka (ReservationNote)
 *  - 'action'— naplánovaná akce (ReservationAction), v UI s tlačítky
 */
final readonly class TimelineItem
{
    /** Menší odchylka od plánu je běžný posun cronu — ta se na ose nepřipomíná. */
    private const int PLAN_DRIFT_SECONDS = 3600;

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
        public bool $dateOnly = false,
        /** Co se se zprávou stane, až nadejde čas — jen u otevřené zprávy hostovi. */
        public ?MessageOutlook $outlook = null,
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

    /**
     * @param bool                $byChat  zpráva k hostovi vede chatem portálu, ne e-mailem
     * @param MessageOutlook|null $outlook jak zpráva dopadne; null u akce, která zprávu neposílá
     */
    public static function fromAction(ReservationAction $action, bool $byChat = false, ?MessageOutlook $outlook = null): self
    {
        // Uzavřená akce patří na osu časem, kdy se opravdu stala (ruční potvrzení
        // přijde často až po termínu); otevřená stojí na svém termínu.
        $at = $action->getExecutedAt() ?? $action->getScheduledFor();
        $byChat = $byChat && $action->getType()->sendsGuestMessage();

        return new self(
            $at,
            'action',
            self::actionIcon($action, $byChat),
            $action->getType()->label(),
            $action->getLabel() !== $action->getType()->label() ? $action->getLabel() : null,
            self::actionMeta($action, $at),
            $action,
            outlook: $outlook,
        );
    }

    /**
     * Zpráva psaná do chatu portálu se od e-mailu pozná už na ose. Uzavřená akce
     * ukazuje, jak dopadla (ručně vyřízená zpráva šla mimo aplikaci), otevřená to,
     * kudy k hostovi povede.
     */
    private static function actionIcon(ReservationAction $action, bool $byChat): string
    {
        if (!$action->getType()->isGuestMessage()) {
            return $action->getType()->icon();
        }

        $chat = match ($action->getDelivery()) {
            ActionDelivery::MANUAL => true,
            ActionDelivery::EMAIL => false,
            null => $byChat,
        };

        return $chat ? '💬' : $action->getType()->icon();
    }

    /** Původ akce, a rozešel-li se výsledek s plánem, i původní termín. */
    private static function actionMeta(ReservationAction $action, \DateTimeImmutable $at): string
    {
        $meta = $action->getOrigin()->label();
        $planned = $action->getScheduledFor();

        if (abs($at->getTimestamp() - $planned->getTimestamp()) >= self::PLAN_DRIFT_SECONDS) {
            $meta .= ' · plánováno ' . $planned->format('d. m. Y H:i');
        }

        return $meta;
    }

    /** Stav akce; u události ani poznámky žádný není. */
    public function getStatus(): ?ActionStatus
    {
        return $this->action?->getStatus();
    }

    /** Akce, která je stále otevřená (PLANNED) → v UI nabídnout odložit/zrušit/spustit. */
    public function isOpenAction(): bool
    {
        return $this->getStatus() === ActionStatus::PLANNED;
    }
}
