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
use App\Entity\GuestMessage;
use App\Entity\Invoice;
use App\Entity\QuickMessage;
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Entity\ReservationNote;
use App\Entity\User;
use App\Enum\ActionDelivery;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\Channel;
use App\Enum\MessageKind;
use App\Enum\ReservationStatus;
use App\Mail\MailSettingsProvider;
use App\Repository\ReservationActionRepository;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ReservationTimelineControllerTest extends WebTestCase
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

        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . GuestMessage::class . ' g')->execute();
        $this->em->createQuery('DELETE FROM ' . ReservationAction::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . QuickMessage::class . ' q')->execute();
        $this->em->createQuery('DELETE FROM ' . ReservationNote::class . ' n')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('timeline@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'timeline@example.com']));
    }

    public function testDetailRendersTimeline(): void
    {
        $r = $this->reservation();

        $this->client->request('GET', '/reservation/' . $r->getId());

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Časová osa', $body);
        self::assertStringContainsString('Rezervace založena', $body);
    }

    public function testAddNoteAppearsOnTimeline(): void
    {
        $r = $this->reservation();
        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());

        $form = $crawler->filter('form[action$="/note"]')->form();
        $form['body'] = 'Domluven pozdní příjezd';
        $form['type'] = 'hovor';
        $this->client->submit($form);

        self::assertResponseRedirects('/reservation/' . $r->getId());
        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Domluven pozdní příjezd', $body);
    }

    public function testCancelActionSetsStatus(): void
    {
        $r = $this->reservation();
        $action = new ReservationAction($r, ActionType::CUSTOM_REMINDER, new \DateTimeImmutable('+2 days'));
        $this->em->persist($action);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        $form = $crawler->filter('form[action$="/action/' . $action->getId() . '/cancel"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/reservation/' . $r->getId());

        $repo = static::getContainer()->get(ReservationActionRepository::class);
        self::assertSame(ActionStatus::CANCELLED, $repo->find($action->getId())->getStatus());
    }

    public function testSendActionDeliversMessageToGuest(): void
    {
        $container = static::getContainer();
        $container->get(SettingRepository::class)->set(MailSettingsProvider::SENDER_EMAIL, 'odesilatel@example.cz');

        $r = $this->reservation();
        $r->setGuestContact(new GuestContact('host@example.com'));
        $action = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('+1 day'));
        $this->em->persist($action);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        $form = $crawler->filter('form[action$="/action/' . $action->getId() . '/send"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/reservation/' . $r->getId());

        $repo = static::getContainer()->get(ReservationActionRepository::class);
        $stored = $repo->find($action->getId());
        self::assertSame(ActionStatus::DONE, $stored->getStatus());
        self::assertSame(ActionDelivery::EMAIL, $stored->getDelivery());

        $sent = $this->em->getRepository(GuestMessage::class)->findOneBy(['reservation' => $r]);
        self::assertNotNull($sent);
        self::assertSame(MessageKind::PRE_ARRIVAL, $sent->getKind());
        self::assertSame('host@example.com', $sent->getToEmail());
    }

    public function testPreviewShowsMessageWithReservationData(): void
    {
        $r = $this->reservation();
        $r->setGuestContact(new GuestContact('host@example.com'));
        $action = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('+1 day'));
        $this->em->persist($action);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        self::assertCount(1, $crawler->filter('[data-preview-url$="/action/' . $action->getId() . '/nahled"]'));

        $this->client->request('GET', '/reservation/action/' . $action->getId() . '/nahled');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('host@example.com', $data['to']);
        self::assertNotSame('', $data['subject']);
        self::assertStringContainsString('Timeline', $data['html']);
    }

    public function testPreviewUsesCustomMessageText(): void
    {
        $r = $this->reservation();
        $action = new ReservationAction($r, ActionType::CUSTOM_MESSAGE, new \DateTimeImmutable('+1 day'));
        $action->setPayload(['text' => 'Klíče budou ve schránce.']);
        $this->em->persist($action);
        $this->em->flush();

        $this->client->request('GET', '/reservation/action/' . $action->getId() . '/nahled');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('Klíče budou ve schránce.', $data['html']);
    }

    public function testPreviewIsMissingForActionWithoutMessage(): void
    {
        $r = $this->reservation();
        $action = new ReservationAction($r, ActionType::CUSTOM_REMINDER, new \DateTimeImmutable('+1 day'));
        $this->em->persist($action);
        $this->em->flush();

        $this->client->request('GET', '/reservation/action/' . $action->getId() . '/nahled');

        self::assertResponseStatusCodeSame(404);
    }

    public function testOtaReservationWithoutEmailOffersChatInsteadOfSending(): void
    {
        $this->em->persist(new QuickMessage('Uvítání', 'Dobrý den.'));
        $r = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('+5 days'));
        $r->setCheckOut(new \DateTimeImmutable('+7 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Chatový Host');
        $this->em->persist($r);
        $this->em->persist(new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('+1 day')));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());

        self::assertCount(0, $crawler->filter('form[action$="/send"]'), 'Bez e-mailu se zpráva odeslat nedá');
        $chatButton = $crawler->filter('.timeline button[data-bs-target="#chatMessage"]');
        self::assertCount(1, $chatButton);
        // Modal si podle akce natáhne její text a umí ji rovnou uzavřít.
        self::assertStringEndsWith('/nahled', (string) $chatButton->attr('data-message-url'));
        self::assertStringEndsWith('/done', (string) $chatButton->attr('data-done-url'));
        self::assertNotSame('', (string) $chatButton->attr('data-done-token'));
    }

    public function testPreviewCarriesPlainTextForChat(): void
    {
        $r = $this->reservation();
        $action = new ReservationAction($r, ActionType::CUSTOM_MESSAGE, new \DateTimeImmutable('+1 day'));
        $action->setPayload(['text' => 'Klíče budou ve schránce.']);
        $this->em->persist($action);
        $this->em->flush();

        $this->client->request('GET', '/reservation/action/' . $action->getId() . '/nahled');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('Klíče budou ve schránce.', $data['text']);
        self::assertStringNotContainsString('<', $data['text']);
        self::assertSame(ActionType::CUSTOM_MESSAGE->label(), $data['label']);
    }

    public function testManualDoneRecordsDeliveryOutsideApp(): void
    {
        $r = $this->reservation();
        $r->setGuestContact(new GuestContact('host@example.com'));
        $action = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('+1 day'));
        $this->em->persist($action);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        $this->client->submit($crawler->filter('form[action$="/action/' . $action->getId() . '/done"]')->form());

        $stored = static::getContainer()->get(ReservationActionRepository::class)->find($action->getId());
        self::assertSame(ActionDelivery::MANUAL, $stored->getDelivery());

        // Osa pak u té zprávy ukazuje chat, i když host e-mail má.
        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        self::assertContains('💬', $this->timelineIcons($crawler));
    }

    public function testChatMessageIsMarkedByIconAndCannotBeRescheduled(): void
    {
        $this->em->persist(new QuickMessage('Uvítání', 'Dobrý den.'));
        $r = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('+5 days'));
        $r->setCheckOut(new \DateTimeImmutable('+7 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Chatový Host');
        $this->em->persist($r);
        $message = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('+1 day'));
        $reminder = new ReservationAction($r, ActionType::CUSTOM_REMINDER, new \DateTimeImmutable('+2 days'));
        $this->em->persist($message);
        $this->em->persist($reminder);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());

        self::assertContains('💬', $this->timelineIcons($crawler));
        self::assertCount(0, $crawler->filter('form[action$="/action/' . $message->getId() . '/reschedule"]'));
        self::assertCount(1, $crawler->filter('form[action$="/action/' . $reminder->getId() . '/reschedule"]'));
    }

    public function testEmailMessageKeepsEnvelopeIconAndOffersNoReschedule(): void
    {
        $r = $this->reservation();
        $r->setGuestContact(new GuestContact('host@example.com'));
        $action = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('+1 day'));
        $this->em->persist($action);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());

        $icons = $this->timelineIcons($crawler);
        self::assertContains('✉️', $icons);
        self::assertNotContains('💬', $icons);
        self::assertCount(0, $crawler->filter('form[action$="/action/' . $action->getId() . '/reschedule"]'));
    }

    public function testReschedulingGuestMessageIsRefused(): void
    {
        $r = $this->reservation();
        $when = new \DateTimeImmutable('+1 day');
        $action = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, $when);
        $this->em->persist($action);
        $this->em->flush();

        // Token akce drží i formulář zrušení — osa jiný pro odložení zprávy nenabízí.
        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        $token = $crawler->filter('form[action$="/action/' . $action->getId() . '/cancel"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/reservation/action/' . $action->getId() . '/reschedule', [
            '_token' => $token,
            'scheduled_for' => (new \DateTimeImmutable('+9 days'))->format('Y-m-d\\TH:i'),
        ]);

        self::assertResponseRedirects('/reservation/' . $r->getId());

        $repo = static::getContainer()->get(ReservationActionRepository::class);
        $stored = $repo->find($action->getId());
        self::assertSame($when->format('Y-m-d H:i'), $stored->getScheduledFor()->format('Y-m-d H:i'));
        self::assertSame(ActionStatus::PLANNED, $stored->getStatus());
    }

    /**
     * @return list<string> ikony položek časové osy
     */
    private function timelineIcons(Crawler $crawler): array
    {
        return $crawler->filter('.timeline .fs-5')->each(static fn (Crawler $node): string => trim($node->text()));
    }

    private function reservation(): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('+5 days'));
        $r->setCheckOut(new \DateTimeImmutable('+7 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Timeline Host');
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }
}
