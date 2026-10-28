<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Checkin;

use App\Checkin\CheckinProgressSummarizer;
use App\Entity\CheckinEvent;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\CheckinStep;
use PHPUnit\Framework\TestCase;

final class CheckinProgressSummarizerTest extends TestCase
{
    private Reservation $reservation;

    protected function setUp(): void
    {
        $this->reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-10-29'));
    }

    public function testNoEventsGiveNoProgress(): void
    {
        self::assertNull((new CheckinProgressSummarizer())->summarize([], new \DateTimeImmutable()));
    }

    public function testCompletedCheckinSummarizesStepsAndCounts(): void
    {
        $events = [
            $this->event('16:31', CheckinStep::VISIT, ['device' => 'mobile']),
            $this->event('16:32', CheckinStep::HOST_FORM, ['new' => true]),
            $this->event('16:33', CheckinStep::DOCUMENT_READ, ['source' => 'live', 'ok' => false]),
            $this->event('16:33', CheckinStep::DOCUMENT_READ, ['source' => 'live', 'ok' => false]),
            $this->event('16:34', CheckinStep::DOCUMENT_READ, ['source' => 'live', 'ok' => true]),
            $this->event('16:40', CheckinStep::HOST_INVALID, ['fields' => ['birthDate', 'documentNumber']]),
            $this->event('16:46', CheckinStep::HOST_SAVED, ['new' => true, 'prefill' => 'live']),
            $this->event('16:50', CheckinStep::HOST_FORM, ['new' => true]),
            $this->event('17:02', CheckinStep::HOST_SAVED, ['new' => true, 'prefill' => null]),
        ];

        $progress = (new CheckinProgressSummarizer())->summarize($events, $this->at('17:02'));

        self::assertNotNull($progress);
        self::assertEquals($this->at('16:31'), $progress->startedAt);
        self::assertSame([
            '16:31 Otevření check-inu · mobil',
            '16:32 Formulář nového hosta',
            '16:33 Čtení dokladu (živá kamera): 3× pokus, rozpoznáno 1×',
            '16:40 Formulář neprošel: Datum narození, Číslo dokladu',
            '16:46 Host uložen · předvyplněno z dokladu (živá kamera)',
            '16:50 Formulář nového hosta',
            '17:02 Host uložen · vyplněno ručně',
            '17:02 Check-in dokončen',
        ], $progress->steps);
        self::assertSame('16:31–17:02 (31 min) · mobil · hosté: 2 (z dokladu 1, ručně 1) · 1× chyba ve formuláři', $progress->summary);
    }

    public function testReadsFromDifferentSourcesStaySeparate(): void
    {
        $events = [
            $this->event('10:00', CheckinStep::DOCUMENT_READ, ['source' => 'photo', 'ok' => false]),
            $this->event('10:01', CheckinStep::DOCUMENT_READ, ['source' => 'manual', 'ok' => true]),
        ];

        $progress = (new CheckinProgressSummarizer())->summarize($events, null);

        self::assertNotNull($progress);
        self::assertCount(2, $progress->steps);
        self::assertStringStartsWith('začátek 10:00, nedokončeno', $progress->summary);
    }

    public function testMultiDayProgressShowsDatesAndUnknownValuesDegrade(): void
    {
        $events = [
            $this->event('20:00', CheckinStep::VISIT, ['device' => 'fridge']),
            $this->event('09:00', CheckinStep::HOST_INVALID, [], '2026-10-29'),
        ];

        $progress = (new CheckinProgressSummarizer())->summarize($events, new \DateTimeImmutable('2026-10-29 10:30'));

        self::assertNotNull($progress);
        self::assertSame('28. 10. 20:00 Otevření check-inu · neznámé zařízení', $progress->steps[0]);
        self::assertSame('29. 10. 09:00 Formulář neprošel: neznámé pole', $progress->steps[1]);
        self::assertStringContainsString('(14 h 30 min)', $progress->summary);
    }

    /** @param array<string, mixed> $details */
    private function event(string $time, CheckinStep $step, array $details, string $date = '2026-10-28'): CheckinEvent
    {
        return new CheckinEvent($this->reservation, $step, $details, $this->at($time, $date));
    }

    private function at(string $time, string $date = '2026-10-28'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date . ' ' . $time);
    }
}
