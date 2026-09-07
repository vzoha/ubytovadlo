<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Timeline;

use App\Entity\MessageTemplate;
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Enum\ActionType;
use App\Enum\Channel;
use App\Enum\GuestMessaging;
use App\Enum\MessageKind;
use App\Enum\MessageOutlook;
use App\Enum\SendMode;
use App\Mail\GuestMessageDelivery;
use App\Mail\MessageTemplateProvider;
use App\Timeline\GuestMessageOutlookResolver;
use PHPUnit\Framework\TestCase;

/**
 * Štítek na časové ose slibuje, co udělá cron — proto se tady kontroluje každá
 * kombinace režimu šablony a cesty ke hostovi.
 */
final class GuestMessageOutlookResolverTest extends TestCase
{
    public function testEmailWithAutomaticTemplateGoesOutOnItsOwn(): void
    {
        self::assertSame(
            MessageOutlook::AUTO_EMAIL,
            $this->resolve(SendMode::AUTO, GuestMessaging::EMAIL),
        );
    }

    public function testEmailWithManualTemplateWaitsForOwner(): void
    {
        $outlook = $this->resolve(SendMode::DRAFT, GuestMessaging::EMAIL);

        self::assertSame(MessageOutlook::MANUAL_EMAIL, $outlook);
        self::assertTrue($outlook->needsOwner());
    }

    /** Do chatu portálu vkládá text ubytovatel — režim šablony na tom nic nemění. */
    public function testChatChannelAlwaysWaitsForOwner(): void
    {
        self::assertSame(MessageOutlook::CHAT, $this->resolve(SendMode::AUTO, GuestMessaging::CHAT));
    }

    public function testDisabledTemplateBlocksSending(): void
    {
        $outlook = $this->resolve(SendMode::OFF, GuestMessaging::EMAIL);

        self::assertSame(MessageOutlook::TEMPLATE_OFF, $outlook);
        self::assertTrue($outlook->isBlocked());
    }

    public function testSilentChannelBlocksSending(): void
    {
        self::assertSame(MessageOutlook::CHANNEL_SILENT, $this->resolve(SendMode::AUTO, GuestMessaging::NONE));
    }

    /** Vlastní zpráva má text od ubytovatele, žádná šablona ji nevypíná. */
    public function testCustomMessageIgnoresTemplateMode(): void
    {
        $outlook = $this->resolve(SendMode::OFF, GuestMessaging::EMAIL, ActionType::CUSTOM_MESSAGE);

        self::assertSame(MessageOutlook::AUTO_EMAIL, $outlook);
    }

    public function testActionWithoutGuestMessageHasNoOutlook(): void
    {
        self::assertNull($this->resolve(SendMode::AUTO, GuestMessaging::EMAIL, ActionType::UBYPORT_EXPORT));
    }

    private function resolve(SendMode $mode, GuestMessaging $route, ActionType $type = ActionType::PRE_ARRIVAL_MESSAGE): ?MessageOutlook
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-09-10'));
        $action = new ReservationAction($reservation, $type, new \DateTimeImmutable('2026-09-09 09:00'));

        $delivery = $this->createStub(GuestMessageDelivery::class);
        $delivery->method('route')->willReturn($route);

        $template = new MessageTemplate(MessageKind::PRE_ARRIVAL, 'Předmět', 'Text');
        $template->setMode($mode);
        $templates = $this->createStub(MessageTemplateProvider::class);
        $templates->method('for')->willReturn($template);

        return (new GuestMessageOutlookResolver($delivery, $templates))->forAction($action);
    }
}
