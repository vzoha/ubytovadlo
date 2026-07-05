<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Connector;

use App\Entity\Connector;
use App\Enum\ConnectorStatus;
use App\Enum\ConnectorType;

/**
 * Zdraví konektoru pro zobrazení: sloučí uložený stav (enabled, poslední běh)
 * s odvozenými signály (nenastaveno, dlouho bez dat). Čistá hodnota — stav se
 * počítá staticky z časů, takže jde otestovat bez DB i hodin.
 */
final class ConnectorHealth
{
    /** Konektor je vypnutý — poller ho přeskakuje. */
    public const STATE_DISABLED = 'disabled';
    /** Zapnutý, ale chybí přístupy → nemá odkud brát data. */
    public const STATE_NEEDS_SETUP = 'needs_setup';
    /** Poslední běh selhal. */
    public const STATE_ERROR = 'error';
    /** Běží, ale dlouho nedorazila žádná data (možná něco nechodí). */
    public const STATE_STALE = 'stale';
    /** Vše v pořádku. */
    public const STATE_OK = 'ok';
    /** Zapnutý a nastavený, ale zatím neproběhl / bez dat. */
    public const STATE_IDLE = 'idle';

    /** Po kolika dnech bez dat u zapnutého konektoru hlásíme „dlouho ticho". */
    public const STALE_AFTER_DAYS = 14;

    private function __construct(
        public readonly ConnectorType $type,
        public readonly bool $enabled,
        public readonly bool $configured,
        public readonly ConnectorStatus $lastStatus,
        public readonly ?\DateTimeImmutable $lastRunAt,
        public readonly ?\DateTimeImmutable $lastActivityAt,
        public readonly ?string $lastError,
        public readonly ?int $staleDays,
        public readonly string $state,
    ) {
    }

    public static function assess(Connector $connector, bool $configured, \DateTimeImmutable $now): self
    {
        $lastActivityAt = $connector->getLastActivityAt();
        // Aktivita v budoucnu (rozhozené datum v hlavičce e-mailu) není „stará" —
        // diff()->days je absolutní, proto ji ošetříme jako 0, ne velké číslo.
        $staleDays = match (true) {
            $lastActivityAt === null => null,
            $lastActivityAt >= $now => 0,
            default => (int) $now->diff($lastActivityAt)->days,
        };

        return new self(
            $connector->getType(),
            $connector->isEnabled(),
            $configured,
            $connector->getLastStatus(),
            $connector->getLastRunAt(),
            $lastActivityAt,
            $connector->getLastError(),
            $staleDays,
            self::deriveState($connector, $configured, $staleDays),
        );
    }

    private static function deriveState(Connector $connector, bool $configured, ?int $staleDays): string
    {
        if (!$connector->isEnabled()) {
            return self::STATE_DISABLED;
        }
        if (!$configured) {
            return self::STATE_NEEDS_SETUP;
        }
        if ($connector->getLastStatus() === ConnectorStatus::ERROR) {
            return self::STATE_ERROR;
        }
        if ($staleDays !== null && $staleDays > self::STALE_AFTER_DAYS) {
            return self::STATE_STALE;
        }

        return $connector->getLastStatus() === ConnectorStatus::OK ? self::STATE_OK : self::STATE_IDLE;
    }

    /** Bootstrap barva odznaku stavu. */
    public function badge(): string
    {
        return match ($this->state) {
            self::STATE_OK => 'success',
            self::STATE_ERROR, self::STATE_STALE => 'warning',
            self::STATE_DISABLED => 'secondary',
            default => 'light',
        };
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            self::STATE_DISABLED => 'Vypnuto',
            self::STATE_NEEDS_SETUP => 'Nenastaveno',
            self::STATE_ERROR => 'Chyba',
            self::STATE_STALE => 'Dlouho bez dat',
            self::STATE_OK => 'V pořádku',
            default => 'Čeká na první běh',
        };
    }
}
