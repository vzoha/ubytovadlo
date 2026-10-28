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
use App\Enum\CheckinStep;

/**
 * Složí kroky hosta z check-inu do čitelného průběhu. Opakované čtení dokladu
 * stejným způsobem jde do jednoho řádku — živá kamera čte snímek po snímku.
 */
class CheckinProgressSummarizer
{
    private const array DEVICES = [
        'mobile' => 'mobil',
        'tablet' => 'tablet',
        'desktop' => 'počítač',
    ];

    private const array SOURCES = [
        'live' => 'živá kamera',
        'photo' => 'fotka',
        'manual' => 'opsaný kód',
    ];

    private const array FIELDS = [
        'lastName' => 'Příjmení',
        'firstName' => 'Jméno',
        'birthDate' => 'Datum narození',
        'isCzechCitizen' => 'Občan ČR',
        'nationality' => 'Státní občanství',
        'documentType' => 'Typ dokladu',
        'documentNumber' => 'Číslo dokladu',
        'residenceAddress' => 'Adresa bydliště',
        'visaNumber' => 'Číslo víza',
        'note' => 'Poznámka',
        '_form' => 'formulář',
    ];

    /**
     * @param CheckinEvent[] $events seřazené vzestupně
     */
    public function summarize(array $events, ?\DateTimeImmutable $completedAt): ?CheckinProgress
    {
        if ($events === []) {
            return null;
        }

        $startedAt = $events[0]->getOccurredAt();
        $end = $completedAt ?? $events[\count($events) - 1]->getOccurredAt();
        $timeFormat = $startedAt->format('Y-m-d') === $end->format('Y-m-d') ? 'H:i' : 'd. m. H:i';

        $steps = [];
        foreach ($this->groupReads($events) as $group) {
            $steps[] = $group[0]->getOccurredAt()->format($timeFormat) . ' ' . $this->describe($group);
        }
        if ($completedAt !== null) {
            $steps[] = $completedAt->format($timeFormat) . ' Check-in dokončen';
        }

        return new CheckinProgress($startedAt, $steps, $this->summaryLine($events, $startedAt, $completedAt, $timeFormat));
    }

    /**
     * Po sobě jdoucí čtení dokladu stejným zdrojem sloučí; ostatní kroky zůstanou samostatně.
     *
     * @param CheckinEvent[] $events
     *
     * @return list<non-empty-list<CheckinEvent>>
     */
    private function groupReads(array $events): array
    {
        $groups = [];
        $previous = null;
        foreach ($events as $event) {
            $last = array_key_last($groups);
            if ($last !== null && $previous !== null && self::isSameRead($previous, $event)) {
                $groups[$last][] = $event;
                continue;
            }
            $groups[] = [$event];
            $previous = $event;
        }

        return $groups;
    }

    private static function isSameRead(CheckinEvent $previous, CheckinEvent $event): bool
    {
        return $event->getStep() === CheckinStep::DOCUMENT_READ
            && $previous->getStep() === CheckinStep::DOCUMENT_READ
            && ($event->getDetails()['source'] ?? null) === ($previous->getDetails()['source'] ?? null);
    }

    /**
     * @param non-empty-list<CheckinEvent> $group
     */
    private function describe(array $group): string
    {
        $event = $group[0];
        $details = $event->getDetails();

        return match ($event->getStep()) {
            CheckinStep::VISIT => 'Otevření check-inu · ' . (self::DEVICES[$details['device'] ?? ''] ?? 'neznámé zařízení'),
            CheckinStep::HOST_FORM => ($details['new'] ?? false) ? 'Formulář nového hosta' : 'Úprava hosta',
            CheckinStep::DOCUMENT_READ => $this->describeReads($group),
            CheckinStep::HOST_INVALID => 'Formulář neprošel: ' . $this->fieldList($details['fields'] ?? []),
            CheckinStep::HOST_SAVED => (($details['new'] ?? false) ? 'Host uložen' : 'Úprava hosta uložena') . ' · ' . $this->prefillLabel($details['prefill'] ?? null),
            CheckinStep::HOST_DELETED => 'Host smazán',
            CheckinStep::BILLING_SAVED => 'Fakturační údaje uloženy',
        };
    }

    /**
     * @param non-empty-list<CheckinEvent> $group
     */
    private function describeReads(array $group): string
    {
        $source = self::SOURCES[$group[0]->getDetails()['source'] ?? ''] ?? 'neznámý zdroj';
        $recognized = \count(array_filter($group, static fn (CheckinEvent $e) => ($e->getDetails()['ok'] ?? false) === true));
        $attempts = \count($group);

        return sprintf('Čtení dokladu (%s): %d× pokus, rozpoznáno %d×', $source, $attempts, $recognized);
    }

    private function prefillLabel(mixed $prefill): string
    {
        $source = \is_string($prefill) ? (self::SOURCES[$prefill] ?? null) : null;

        return $source !== null ? 'předvyplněno z dokladu (' . $source . ')' : 'vyplněno ručně';
    }

    private function fieldList(mixed $fields): string
    {
        if (!\is_array($fields) || $fields === []) {
            return 'neznámé pole';
        }

        return implode(', ', array_map(static fn ($f) => self::FIELDS[$f] ?? (string) $f, $fields));
    }

    /**
     * @param CheckinEvent[] $events
     */
    private function summaryLine(array $events, \DateTimeImmutable $startedAt, ?\DateTimeImmutable $completedAt, string $timeFormat): string
    {
        $parts = [$this->spanLabel($startedAt, $completedAt, $timeFormat)];

        $devices = array_unique(array_map(
            static fn (CheckinEvent $e) => self::DEVICES[$e->getDetails()['device'] ?? ''] ?? 'neznámé zařízení',
            self::ofStep($events, CheckinStep::VISIT),
        ));
        if ($devices !== []) {
            $parts[] = implode(', ', $devices);
        }

        $newHosts = array_filter(self::ofStep($events, CheckinStep::HOST_SAVED), static fn (CheckinEvent $e) => ($e->getDetails()['new'] ?? false) === true);
        $fromDocument = \count(array_filter($newHosts, static fn (CheckinEvent $e) => CheckinProgressRecorder::documentSource($e->getDetails()['prefill'] ?? null) !== null));
        if ($newHosts !== []) {
            $parts[] = sprintf('hosté: %d (z dokladu %d, ručně %d)', \count($newHosts), $fromDocument, \count($newHosts) - $fromDocument);
        }

        $invalid = \count(self::ofStep($events, CheckinStep::HOST_INVALID));
        if ($invalid > 0) {
            $parts[] = sprintf('%d× chyba ve formuláři', $invalid);
        }

        return implode(' · ', $parts);
    }

    private function spanLabel(\DateTimeImmutable $startedAt, ?\DateTimeImmutable $completedAt, string $timeFormat): string
    {
        if ($completedAt === null) {
            return 'začátek ' . $startedAt->format($timeFormat) . ', nedokončeno';
        }
        $minutes = intdiv(max(0, $completedAt->getTimestamp() - $startedAt->getTimestamp()), 60);

        return sprintf('%s–%s (%s)', $startedAt->format($timeFormat), $completedAt->format($timeFormat), $this->duration($minutes));
    }

    /**
     * @param CheckinEvent[] $events
     *
     * @return CheckinEvent[]
     */
    private static function ofStep(array $events, CheckinStep $step): array
    {
        return array_filter($events, static fn (CheckinEvent $e) => $e->getStep() === $step);
    }

    private function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }

        return sprintf('%d h %d min', intdiv($minutes, 60), $minutes % 60);
    }
}
