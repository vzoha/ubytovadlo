<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Entity\MessageTemplate;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\MessageKind;
use App\Enum\TimingAnchor;
use App\Mail\MessageScheduleResolver;
use PHPUnit\Framework\TestCase;

final class MessageScheduleResolverTest extends TestCase
{
    private MessageScheduleResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new MessageScheduleResolver();
    }

    public function testResolvesDaysBeforeCheckInAtGivenHour(): void
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-08-10 15:00'));
        $template = $this->template()->setTiming(TimingAnchor::CHECK_IN, -3, '09:00');

        $when = $this->resolver->resolve($template, $reservation);

        self::assertNotNull($when);
        self::assertSame('2026-08-07 09:00', $when->format('Y-m-d H:i'));
    }

    public function testResolvesDaysAfterCheckOut(): void
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-08-10'));
        $reservation->setCheckOut(new \DateTimeImmutable('2026-08-14'));
        $template = $this->template()->setTiming(TimingAnchor::CHECK_OUT, 1, '10:00');

        $when = $this->resolver->resolve($template, $reservation);

        self::assertNotNull($when);
        self::assertSame('2026-08-15 10:00', $when->format('Y-m-d H:i'));
    }

    public function testEmptyHourKeepsAnchorTime(): void
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-08-10'));
        $template = $this->template()->setTiming(TimingAnchor::CREATED, 0, null);

        $when = $this->resolver->resolve($template, $reservation);

        self::assertNotNull($when);
        self::assertEquals($reservation->getCreatedAt(), $when);
    }

    public function testMissingAnchorDateYieldsNull(): void
    {
        // Rezervace bez odjezdu → post-stay se nedá naplánovat.
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-08-10'));
        $template = $this->template()->setTiming(TimingAnchor::CHECK_OUT, 1, '10:00');

        self::assertNull($this->resolver->resolve($template, $reservation));
    }

    public function testUnscheduledTemplateYieldsNull(): void
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-08-10'));

        self::assertNull($this->resolver->resolve($this->template(), $reservation));
    }

    private function template(): MessageTemplate
    {
        return new MessageTemplate(MessageKind::PRE_ARRIVAL, 'Předmět', 'Tělo');
    }
}
