<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\GuestMail;

use App\Email\GuestInbox\MailAddress;
use App\Entity\Embeddable\GuestContact;
use App\Entity\GuestEmail;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\EmailDirection;
use App\GuestMail\GuestEmailReplier;
use App\Mail\GuestEmailFactory;
use App\Mail\MailSettingsProvider;
use App\Repository\SettingRepository;
use App\Tests\Mail\CapturingMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class GuestEmailReplierTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        foreach (['guest_email', 'reservation', 'customer'] as $table) {
            $em->getConnection()->executeStatement('DELETE FROM ' . $table);
        }
        static::getContainer()->get(SettingRepository::class)->set(MailSettingsProvider::SENDER_EMAIL, 'info@example.com');
        $em->flush();
    }

    public function testReplyGoesIntoThreadIsStoredAndAnswersQuestion(): void
    {
        $question = $this->question(threadIds: ['first@example.com']);
        $mailer = $this->capturingMailer();

        $reply = $this->replier($mailer)->reply($question, '', "Dobrý den,\n\nano, máme volno.");

        $sent = $mailer->sent;
        self::assertInstanceOf(Email::class, $sent);
        self::assertSame('Re: Dotaz na volno', $sent->getSubject());
        self::assertSame('<question@example.com>', $sent->getHeaders()->get('In-Reply-To')?->getBodyAsString());
        self::assertSame('<first@example.com> <question@example.com>', $sent->getHeaders()->get('References')?->getBodyAsString());
        self::assertSame('<' . $reply->getMessageId() . '>', $sent->getHeaders()->get('Message-ID')?->getBodyAsString());
        self::assertSame(EmailDirection::OUTBOUND, $reply->getDirection());
        self::assertSame($question->getReservation(), $reply->getReservation());
        self::assertTrue($question->isAnswered());
    }

    public function testFailedSendStoresNothing(): void
    {
        $question = $this->question();
        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('Connection refused');
            }
        };

        try {
            $this->replier($mailer)->reply($question, '', 'Dobrý den');
            self::fail('Chyba odeslání se má propsat volajícímu.');
        } catch (TransportException) {
        }

        self::assertFalse($question->isAnswered());
        self::assertCount(1, $this->em->getRepository(GuestEmail::class)->findAll());
    }

    public function testEmptyTextIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->replier($this->capturingMailer())->reply($this->question(), '', "  \n ");
    }

    public function testOwnMessageCannotBeRepliedTo(): void
    {
        $own = new GuestEmail(EmailDirection::OUTBOUND, 'own@example.com', new MailAddress(null, 'jana@example.com'), 'Nabídka', new \DateTimeImmutable('2030-06-01'), 'Dobrý den');

        $this->expectException(\InvalidArgumentException::class);
        $this->replier($this->capturingMailer())->reply($own, '', 'Dobrý den');
    }

    /** @param list<string> $threadIds */
    private function question(array $threadIds = []): GuestEmail
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2030-07-12'));
        $reservation->setCheckOut(new \DateTimeImmutable('2030-07-15'));
        $reservation->setGuestName('Jana Testová');
        $reservation->setGuestContact(new GuestContact('jana@example.com'));
        $this->em->persist($reservation);

        $question = new GuestEmail(EmailDirection::INBOUND, 'question@example.com', new MailAddress('Jana Testová', 'jana@example.com'), 'Dotaz na volno', new \DateTimeImmutable('2030-06-01'), 'Máte volno?', $threadIds);
        $this->em->persist($question);
        $this->em->flush();
        $question->completeMatch($reservation->getCustomer(), $reservation);

        return $question;
    }

    private function capturingMailer(): CapturingMailer
    {
        return new CapturingMailer();
    }

    private function replier(MailerInterface $mailer): GuestEmailReplier
    {
        $container = static::getContainer();

        return new GuestEmailReplier($mailer, $container->get(GuestEmailFactory::class), $this->em, new MockClock('2030-06-02 09:00'));
    }
}
