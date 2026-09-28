<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Embeddable\GuestContact;
use App\Entity\Invoice;
use App\Entity\InvoiceLink;
use App\Entity\QuickMessage;
use App\Entity\Reservation;
use App\Entity\ReservationNote;
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\NoteType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Rychlá zpráva otevřená ve WhatsAppu, SMS nebo zkopírovaná do chatu se zapíše na časovou osu. */
final class SentMessageControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        // Faktura drží rezervaci přes RESTRICT — uklidíme i doklady, které nechal jiný test.
        $this->em->createQuery('DELETE FROM ' . InvoiceLink::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . ReservationNote::class . ' n')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . QuickMessage::class . ' q')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('sent-message-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->persist(new QuickMessage('Uvítání', 'Dobrý den, těšíme se na vás.'));
        $this->em->flush();

        $user = $container->get(UserRepository::class)->findOneBy(['email' => 'sent-message-test@example.com']);
        \assert($user instanceof User);
        $this->client->loginUser($user);
    }

    public function testQuickMessageIsRecordedWithAuthor(): void
    {
        $reservation = $this->reservation();

        $this->client->request('POST', '/reservation/' . $reservation->getId() . '/odeslana-zprava', [
            '_token' => $this->token($reservation),
            'channel' => 'whatsapp',
            'label' => 'Uvítání',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->em->clear();
        $note = $this->em->getRepository(ReservationNote::class)->findOneBy([]);
        self::assertInstanceOf(ReservationNote::class, $note);
        self::assertSame(NoteType::ZPRAVA, $note->getType());
        self::assertSame('WhatsApp: Uvítání', $note->getBody());
        self::assertSame('sent-message-test@example.com', $note->getAuthor()?->getUserIdentifier());
    }

    public function testChatCopyIsRecorded(): void
    {
        $reservation = $this->reservation();

        $this->client->request('POST', '/reservation/' . $reservation->getId() . '/odeslana-zprava', [
            '_token' => $this->token($reservation),
            'channel' => 'copy',
            'label' => 'Uvítání',
        ]);

        self::assertSame('Zkopírováno do chatu: Uvítání', $this->em->getConnection()->fetchOne('SELECT body FROM reservation_note'));
    }

    public function testUnknownChannelIsRejected(): void
    {
        $reservation = $this->reservation();

        $this->client->request('POST', '/reservation/' . $reservation->getId() . '/odeslana-zprava', [
            '_token' => $this->token($reservation),
            'channel' => 'fax',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testRequiresCsrfToken(): void
    {
        $reservation = $this->reservation();

        $this->client->request('POST', '/reservation/' . $reservation->getId() . '/odeslana-zprava', ['_token' => 'x', 'channel' => 'sms']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testQuickMessageLinksCarryLabelForRecording(): void
    {
        $reservation = $this->reservation();
        $crawler = $this->client->request('GET', '/reservation/' . $reservation->getId());

        self::assertCount(1, $crawler->filter('a[data-sent-channel="whatsapp"][data-sent-label="Uvítání"]'));
        self::assertCount(1, $crawler->filter('a[data-sent-channel="sms"][data-sent-label="Uvítání"]'));
    }

    /** Token z detailu rezervace (skript, který zápis posílá). */
    private function token(Reservation $reservation): string
    {
        $this->client->request('GET', '/reservation/' . $reservation->getId());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match("~body\\.append\\('_token', \"([^\"]+)\"\\)~", $html, $m));

        return $m[1];
    }

    private function reservation(): Reservation
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('+10 days'));
        $reservation->setCheckOut(new \DateTimeImmutable('+13 days'));
        $reservation->setGuestName('Zapisovaný Host');
        $reservation->setGuestContact(new GuestContact(phone: '776 123 456'));
        $this->em->persist($reservation);
        $this->em->flush();

        return $reservation;
    }
}
