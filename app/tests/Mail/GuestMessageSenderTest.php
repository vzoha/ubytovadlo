<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Config\LogoStorage;
use App\Entity\Embeddable\GuestContact;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\MessageKind;
use App\Mail\GuestMessageRenderer;
use App\Mail\GuestMessageSender;
use App\Mail\MailSettingsProvider;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class GuestMessageSenderTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $em->getConnection()->executeStatement('DELETE FROM guest_message');
        static::getContainer()->get(SettingRepository::class)->set(MailSettingsProvider::SENDER_EMAIL, 'info@example.com');
        $em->flush();
    }

    public function testSentMessageCarriesStoredMessageIdForReplyMatching(): void
    {
        $mailer = new class implements MailerInterface {
            public ?RawMessage $sent = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent = $message;
            }
        };

        $message = $this->sender($mailer)->send($this->reservation(), MessageKind::RESERVATION_CONFIRMED);

        self::assertNull($message->getError());
        self::assertInstanceOf(Email::class, $mailer->sent);
        self::assertNotNull($message->getMessageId());
        self::assertSame('<' . $message->getMessageId() . '>', $mailer->sent->getHeaders()->get('Message-ID')?->getBodyAsString());
    }

    public function testFailedMessageHasNoMessageId(): void
    {
        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new \RuntimeException('Connection refused');
            }
        };

        $message = $this->sender($mailer)->send($this->reservation(), MessageKind::RESERVATION_CONFIRMED);

        self::assertNotNull($message->getError());
        self::assertNull($message->getMessageId());
    }

    private function sender(MailerInterface $mailer): GuestMessageSender
    {
        $container = static::getContainer();

        return new GuestMessageSender(
            $mailer,
            $container->get(GuestMessageRenderer::class),
            $container->get(MailSettingsProvider::class),
            $this->em,
            new NullLogger(),
            $container->get(LogoStorage::class),
        );
    }

    private function reservation(): Reservation
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2030-07-12'));
        $reservation->setCheckOut(new \DateTimeImmutable('2030-07-15'));
        $reservation->setGuestName('Jana Testová');
        $reservation->setGuestContact(new GuestContact('jana@example.com'));
        $this->em->persist($reservation);

        return $reservation;
    }
}
