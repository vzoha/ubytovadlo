<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Entity\GuestMessage;
use App\Entity\MessageTemplate;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\GuestMessageStatus;
use App\Enum\MessageKind;
use App\Enum\OwnerNotificationType;
use App\Enum\ReservationStatus;
use App\Mail\GuestMessageSender;
use App\Mail\MessageTemplateProvider;
use App\Mail\ReservationConfirmation;
use App\Notification\OwnerNotifier;
use App\Repository\GuestMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ReservationConfirmationTest extends TestCase
{
    private GuestMessageSender&MockObject $sender;
    private MessageTemplateProvider&MockObject $templates;
    private GuestMessageRepository&MockObject $messages;
    private OwnerNotifier&MockObject $notifier;

    protected function setUp(): void
    {
        $this->sender = $this->createMock(GuestMessageSender::class);
        $this->templates = $this->createMock(MessageTemplateProvider::class);
        $this->messages = $this->createMock(GuestMessageRepository::class);
        $this->notifier = $this->createMock(OwnerNotifier::class);
    }

    public function testExplicitConfirmSendsAndFlipsStatus(): void
    {
        $reservation = $this->reservation(ReservationStatus::NEEDS_DETAILS);
        $this->sender->method('canSend')->willReturn(true);
        $this->sender->expects(self::once())->method('send')->willReturn($this->sent($reservation));

        $result = $this->service()->confirm($reservation, true);

        self::assertTrue($result->emailSent);
        self::assertTrue($result->statusChanged);
        self::assertSame(ReservationStatus::CONFIRMED, $reservation->getStatus());
    }

    public function testAutoConfirmSkipsWhenTemplateDisabledButStillConfirms(): void
    {
        $reservation = $this->reservation(ReservationStatus::NEEDS_DETAILS);
        $this->sender->method('canSend')->willReturn(true);
        $this->templates->method('for')->willReturn($this->template(false));
        $this->sender->expects(self::never())->method('send');

        $result = $this->service()->confirm($reservation, false);

        self::assertFalse($result->emailSent);
        self::assertTrue($result->statusChanged);
        self::assertSame(ReservationStatus::CONFIRMED, $reservation->getStatus());
    }

    public function testAutoConfirmDoesNotResendWhenAlreadySent(): void
    {
        $reservation = $this->reservation(ReservationStatus::CONFIRMED);
        $this->sender->method('canSend')->willReturn(true);
        $this->templates->method('for')->willReturn($this->template(true));
        $this->messages->method('hasSent')->willReturn(true);
        $this->sender->expects(self::never())->method('send');

        $result = $this->service()->confirm($reservation, false);

        self::assertFalse($result->emailSent);
        self::assertFalse($result->statusChanged);
    }

    public function testCancelledReservationIsNoop(): void
    {
        $reservation = $this->reservation(ReservationStatus::CANCELLED);
        $this->sender->expects(self::never())->method('send');

        $result = $this->service()->confirm($reservation, true);

        self::assertFalse($result->emailSent);
        self::assertFalse($result->statusChanged);
        self::assertSame(ReservationStatus::CANCELLED, $reservation->getStatus());
    }

    public function testNoGuestEmailFlipsStatusButSkipsEmail(): void
    {
        $reservation = $this->reservation(ReservationStatus::NEEDS_DETAILS);
        $this->sender->method('canSend')->willReturn(false);
        $this->sender->expects(self::never())->method('send');

        $result = $this->service()->confirm($reservation, true);

        self::assertFalse($result->emailSent);
        self::assertTrue($result->statusChanged);
        self::assertNotNull($result->skipReason);
    }

    public function testFailedSendNotifiesOwner(): void
    {
        $reservation = $this->reservation(ReservationStatus::CONFIRMED);
        $this->sender->method('canSend')->willReturn(true);
        $this->sender->method('send')->willReturn($this->failed($reservation));
        $this->notifier->expects(self::once())->method('notify')
            ->with(OwnerNotificationType::GUEST_MESSAGE_FAILED, $reservation, self::anything());

        $result = $this->service()->confirm($reservation, true);

        self::assertFalse($result->emailSent);
        self::assertNotNull($result->skipReason);
    }

    private function service(): ReservationConfirmation
    {
        $em = $this->createMock(EntityManagerInterface::class);

        return new ReservationConfirmation($this->sender, $this->templates, $this->messages, $em, $this->notifier);
    }

    private function reservation(ReservationStatus $status): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('+10 days'));
        $r->setStatus($status);
        $r->setGuestEmail('host@example.com');

        return $r;
    }

    private function template(bool $enabled): MessageTemplate
    {
        return (new MessageTemplate(MessageKind::RESERVATION_CONFIRMED, 'Předmět', 'Tělo'))->setEnabled($enabled);
    }

    private function sent(Reservation $reservation): GuestMessage
    {
        return new GuestMessage($reservation, MessageKind::RESERVATION_CONFIRMED, 'host@example.com', 'Předmět', GuestMessageStatus::SENT, null);
    }

    private function failed(Reservation $reservation): GuestMessage
    {
        return new GuestMessage($reservation, MessageKind::RESERVATION_CONFIRMED, 'host@example.com', 'Předmět', GuestMessageStatus::FAILED, 'SMTP down');
    }
}
