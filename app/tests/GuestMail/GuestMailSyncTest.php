<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\GuestMail;

use App\Email\GuestInbox\GuestInboxException;
use App\Entity\Embeddable\GuestContact;
use App\Entity\GuestEmail;
use App\Entity\GuestMessage;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\GuestMessageStatus;
use App\Enum\MessageKind;
use App\Enum\ReservationStatus;
use App\GuestMail\GuestEmailMatcher;
use App\GuestMail\GuestMailSync;
use App\GuestMail\GuestMailSyncResult;
use App\Repository\GuestEmailRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/** Stažení korespondence ze schránky hostů a její párování s hosty a rezervacemi. */
final class GuestMailSyncTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FakeGuestInbox $inbox;
    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $connection = $em->getConnection();
        foreach (['guest_email', 'guest_message', 'reservation', 'customer', 'setting'] as $table) {
            $connection->executeStatement('DELETE FROM ' . $table);
        }

        $this->inbox = new FakeGuestInbox();
        $this->clock = new MockClock(new \DateTimeImmutable('2030-06-10 12:00'));
    }

    public function testInboundFromGuestWithSingleUpcomingStayGetsReservation(): void
    {
        $stay = $this->reservation('jana@example.com', '2030-07-12');
        $this->inbox->receive('1', 'jana@example.com', 'Dotaz na parkování', '2030-06-09 10:00');

        $result = $this->sync();

        $email = $this->onlyEmail();
        self::assertSame(1, $result->inbound);
        self::assertSame($stay->getCustomer(), $email->getCustomer());
        self::assertSame($stay, $email->getReservation());
    }

    public function testNewsletterAndOwnMessagesAreSkipped(): void
    {
        $this->inbox->receive('1', 'novinky@example.com', 'Akce týdne', '2030-06-09 10:00', automated: true);
        $this->inbox->receive('2', FakeGuestInbox::OWN, 'Poznámka pro sebe', '2030-06-09 11:00');

        self::assertSame(0, $this->sync()->inbound);
    }

    public function testSecondRunDoesNotImportOrFetchAgain(): void
    {
        $this->inbox->receive('1', 'novy@example.com', 'Volno v srpnu?', '2030-06-09 10:00');
        $this->sync();
        $fetches = $this->inbox->fetches;

        self::assertSame(0, $this->sync()->inbound);
        self::assertSame($fetches, $this->inbox->fetches);
    }

    public function testOwnerReplyInThreadMarksQuestionAnsweredAndInheritsReservation(): void
    {
        $this->reservation('jana@example.com', '2030-07-12');
        $question = $this->inbox->receive('1', 'jana@example.com', 'Dotaz', '2030-06-09 10:00');
        $this->inbox->send('5', 'jana@example.com', 'Re: Dotaz', '2030-06-09 12:00', [$question]);

        $result = $this->sync();

        $emails = $this->emails()->findBy([], ['sentAt' => 'ASC']);
        self::assertSame(1, $result->outbound);
        self::assertTrue($emails[0]->isAnswered());
        self::assertSame($emails[0]->getReservation(), $emails[1]->getReservation());
    }

    public function testReplyToAppMessagePicksItsReservationAmongSeveral(): void
    {
        $this->reservation('jana@example.com', '2030-07-12');
        $december = $this->reservation('jana@example.com', '2030-12-20');
        $this->em->persist(new GuestMessage($december, MessageKind::RESERVATION_CONFIRMED, 'jana@example.com', 'Potvrzení', GuestMessageStatus::SENT, messageId: 'app-1@example.com'));
        $this->em->flush();
        $this->inbox->receive('1', 'jana@example.com', 'Re: Potvrzení', '2030-06-09 10:00', threadIds: ['app-1@example.com']);

        $this->sync();

        self::assertSame($december, $this->onlyEmail()->getReservation());
    }

    public function testQuotedReservationCodePicksReservationAmongSeveral(): void
    {
        $this->reservation('jana@example.com', '2030-07-12');
        $december = $this->reservation('jana@example.com', '2030-12-20', code: 'HMABC123');
        $this->inbox->receive('1', 'jana@example.com', 'Dotaz', '2030-06-09 10:00', 'K rezervaci hmabc123: přijedeme dřív.');

        $this->sync();

        self::assertSame($december, $this->onlyEmail()->getReservation());
    }

    public function testTwoPossibleStaysLeaveOnlyCustomer(): void
    {
        $july = $this->reservation('jana@example.com', '2030-07-12');
        $this->reservation('jana@example.com', '2030-12-20');
        $this->inbox->receive('1', 'jana@example.com', 'Dotaz', '2030-06-09 10:00');

        $this->sync();

        $email = $this->onlyEmail();
        self::assertSame($july->getCustomer(), $email->getCustomer());
        self::assertNull($email->getReservation());
    }

    public function testStayEndedLongAgoIsNotGuessed(): void
    {
        $this->reservation('jana@example.com', '2030-04-01');
        $this->reservation('jana@example.com', '2030-02-01', status: ReservationStatus::CANCELLED);
        $this->inbox->receive('1', 'jana@example.com', 'Dotaz', '2030-06-09 10:00');

        $this->sync();

        self::assertNull($this->onlyEmail()->getReservation());
    }

    public function testInquiryIsMatchedOnceReservationExists(): void
    {
        $this->inbox->receive('1', 'jana@example.com', 'Máte volno?', '2030-06-09 10:00');
        $this->sync();
        self::assertNull($this->onlyEmail()->getCustomer());

        $stay = $this->reservation('jana@example.com', '2030-07-12');
        $this->sync();

        self::assertSame($stay, $this->onlyEmail()->getReservation());
    }

    public function testDuplicateDeliveryIsStoredOnce(): void
    {
        $this->inbox->receive('1', 'jana@example.com', 'Dotaz', '2030-06-09 10:00');
        $this->inbox->duplicate('1', '2');

        self::assertSame(1, $this->sync()->inbound);
    }

    public function testUnreadableMessageIsSkippedWithWarning(): void
    {
        $this->inbox->receive('1', 'jana@example.com', 'Rozbitá', '2030-06-09 10:00');
        $this->inbox->receive('2', 'petr@example.com', 'Dotaz', '2030-06-09 11:00');
        $this->inbox->unreadable = ['1'];

        $result = $this->sync();

        self::assertSame(1, $result->inbound);
        self::assertSame(['Načtení zprávy 1 ze schránky hostů selhalo.'], $result->warnings);
    }

    public function testCustomerFromMergeSurvivesNextRun(): void
    {
        $keep = $this->reservation('jana@example.com', '2030-07-12')->getCustomer();
        $this->inbox->receive('1', 'jana.stara@example.com', 'Dotaz', '2030-06-09 10:00');
        $this->sync();
        \assert($keep !== null);
        $this->onlyEmail()->completeMatch($keep, null);
        $this->em->flush();

        $this->sync();

        self::assertSame($keep, $this->onlyEmail()->getCustomer());
    }

    public function testMatchedStayIsKeptAfterGuestBooksAnother(): void
    {
        $july = $this->reservation('jana@example.com', '2030-07-12');
        $this->inbox->receive('1', 'jana@example.com', 'Dotaz', '2030-06-09 10:00');
        $this->sync();

        $this->reservation('jana@example.com', '2030-12-20');
        $this->sync();

        self::assertSame($july, $this->onlyEmail()->getReservation());
    }

    public function testOwnerChoiceIsNotOverwritten(): void
    {
        $this->reservation('jana@example.com', '2030-07-12');
        $december = $this->reservation('jana@example.com', '2030-12-20');
        $this->inbox->receive('1', 'jana@example.com', 'Dotaz', '2030-06-09 10:00');
        $this->sync();
        $this->onlyEmail()->assignReservation($december);
        $this->em->flush();

        $this->sync();

        self::assertSame($december, $this->onlyEmail()->getReservation());
    }

    public function testMissingSentFolderIsWarningAndInboxStillImports(): void
    {
        $this->inbox->failures['SENT'] = new GuestInboxException('Ve schránce hostů chybí složka Sent.');
        $this->inbox->receive('1', 'jana@example.com', 'Dotaz', '2030-06-09 10:00');

        $result = $this->sync();

        self::assertSame(1, $result->inbound);
        self::assertSame(['Ve schránce hostů chybí složka Sent.'], $result->warnings);
    }

    public function testSentFolderFailingOnceIsBackfilledLater(): void
    {
        $this->inbox->failures['SENT'] = new GuestInboxException('Ve schránce hostů chybí složka Sent.');
        $this->inbox->send('5', 'jana@example.com', 'Nabídka termínu', '2030-05-01 12:00');
        $this->sync();

        unset($this->inbox->failures['SENT']);
        $this->clock->modify('+10 days');

        self::assertSame(1, $this->sync()->outbound);
    }

    public function testUnreadableInboxFailsWithoutRecordingSync(): void
    {
        $this->inbox->failures['INBOX'] = new GuestInboxException('Připojení ke schránce hostů selhalo.');

        try {
            $this->sync();
            self::fail('Chyba doručených se má propsat volajícímu.');
        } catch (GuestInboxException) {
        }

        self::assertNull(static::getContainer()->get(SettingRepository::class)->getString(GuestMailSync::SYNCED_AT_KEY));
    }

    private function sync(): GuestMailSyncResult
    {
        $container = static::getContainer();

        $sync = new GuestMailSync(
            $this->inbox,
            $this->emails(),
            $container->get(GuestEmailMatcher::class),
            $container->get(SettingRepository::class),
            $this->em,
            $this->clock,
        );

        return $sync->sync();
    }

    private function reservation(string $email, string $checkIn, ?string $code = null, ReservationStatus $status = ReservationStatus::CONFIRMED): Reservation
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable($checkIn));
        $reservation->setCheckOut((new \DateTimeImmutable($checkIn))->modify('+3 days'));
        $reservation->setGuestName('Jana Testová');
        $reservation->setGuestContact(new GuestContact($email));
        $reservation->setExternalId($code);
        $reservation->setStatus($status);
        $this->em->persist($reservation);
        $this->em->flush();

        return $reservation;
    }

    private function emails(): GuestEmailRepository
    {
        return static::getContainer()->get(GuestEmailRepository::class);
    }

    private function onlyEmail(): GuestEmail
    {
        $emails = $this->emails()->findAll();
        self::assertCount(1, $emails);

        return $emails[0];
    }
}
