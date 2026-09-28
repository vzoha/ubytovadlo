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
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Entity\ReservationNote;
use App\Entity\User;
use App\Enum\ActionDelivery;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Připomínka doplatku přes WhatsApp, SMS nebo chat: text s platebními údaji
 * (a odkazem na fakturu, je-li vystavená), po odeslání se akce uzavře.
 */
final class BalanceReminderControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $pdf;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->clear();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('reminder-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $user = $container->get(UserRepository::class)->findOneBy(['email' => 'reminder-test@example.com']);
        \assert($user instanceof User);
        $this->client->loginUser($user);

        $this->pdf = sys_get_temp_dir() . '/balance-reminder-test.pdf';
        file_put_contents($this->pdf, "%PDF-1.4\n%test\n");
    }

    protected function tearDown(): void
    {
        $this->clear();
        @unlink($this->pdf);

        parent::tearDown();
    }

    private function clear(): void
    {
        $this->em->createQuery('DELETE FROM ' . InvoiceLink::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . ReservationAction::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . ReservationNote::class . ' n')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
    }

    public function testWhatsappWithUnpaidInvoiceCarriesLinkAndClosesReminder(): void
    {
        $action = $this->reminder();
        $this->invoice($action->getReservation());

        $location = $this->send($action->getReservation(), 'whatsapp');

        self::assertStringStartsWith('https://wa.me/420776123456?text=', $location);
        $text = $this->textOf($location);
        self::assertStringContainsString('připomínáme doplatek', $text);
        self::assertStringContainsString('/f/', $text);

        $closed = $this->reload($action);
        self::assertSame(ActionStatus::DONE, $closed->getStatus());
        self::assertSame(ActionDelivery::MANUAL, $closed->getDelivery());
        self::assertSame('Odesláno přes WhatsApp.', $closed->getResult());
    }

    public function testWithoutInvoiceTextHasNoLink(): void
    {
        $action = $this->reminder();

        $text = $this->textOf($this->send($action->getReservation(), 'sms'));

        self::assertStringContainsString('připomínáme doplatek', $text);
        self::assertStringNotContainsString('/f/', $text);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice_link'));
        self::assertSame('Odesláno SMS.', $this->reload($action)->getResult());
    }

    /** Uzavřená připomínka je na ose sama — zpráva se nezapíše podruhé. */
    public function testClosedReminderIsNotRecordedTwice(): void
    {
        $action = $this->reminder();
        $this->send($action->getReservation(), 'whatsapp');

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM reservation_note'));
    }

    public function testReminderWithoutOpenActionLandsOnTimeline(): void
    {
        $action = $this->reminder();
        $action->cancel();
        $this->em->flush();

        $this->send($action->getReservation(), 'sms');

        self::assertSame('SMS: Připomínka doplatku', $this->em->getConnection()->fetchOne('SELECT body FROM reservation_note'));
    }

    /** Z chatu appka odeslání nevidí — připomínka zůstane otevřená. */
    public function testChatTextLeavesReminderOpen(): void
    {
        $action = $this->reminder();

        $this->client->request('POST', '/reservation/' . $action->getReservation()->getId() . '/pripominka-doplatku', [
            '_token' => $this->token($action->getReservation()),
            'channel' => 'copy',
        ]);

        self::assertResponseIsSuccessful();
        /** @var array{text: string} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('připomínáme doplatek', $data['text']);
        self::assertSame(ActionStatus::PLANNED, $this->reload($action)->getStatus());
    }

    public function testClosedReminderCanBeReopened(): void
    {
        $action = $this->reminder();
        $this->send($action->getReservation(), 'whatsapp');

        $crawler = $this->client->request('GET', '/reservation/' . $action->getReservation()->getId());
        $form = $crawler->filter('form[action$="/reservation/action/' . $action->getId() . '/znovu-otevrit"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects();

        $reopened = $this->reload($action);
        self::assertSame(ActionStatus::PLANNED, $reopened->getStatus());
        self::assertNull($reopened->getDelivery());
    }

    public function testMailedReminderCannotBeReopened(): void
    {
        $action = $this->reminder();
        $action->markDone('Odesláno.', ActionDelivery::EMAIL);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/reservation/' . $action->getReservation()->getId());
        self::assertCount(0, $crawler->filter('form[action$="/znovu-otevrit"]'));

        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/reservation/action/' . $action->getId() . '/znovu-otevrit', ['_token' => $token]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function send(Reservation $reservation, string $channel): string
    {
        $this->client->request('POST', '/reservation/' . $reservation->getId() . '/pripominka-doplatku', [
            '_token' => $this->token($reservation),
            'channel' => $channel,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_SEE_OTHER);

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /** Token z formuláře „Připomínka doplatku" v menu u WhatsAppu. */
    private function token(Reservation $reservation): string
    {
        $crawler = $this->client->request('GET', '/reservation/' . $reservation->getId());
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('form[action$="/pripominka-doplatku"] input[name="_token"]')->first()->attr('value');
    }

    private function textOf(string $location): string
    {
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);

        return (string) ($query['text'] ?? $query['body'] ?? '');
    }

    private function reload(ReservationAction $action): ReservationAction
    {
        $this->em->clear();
        $fresh = $this->em->find(ReservationAction::class, $action->getId());
        \assert($fresh instanceof ReservationAction);

        return $fresh;
    }

    private function reminder(ActionType $type = ActionType::BALANCE_REMINDER): ReservationAction
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('+10 days'));
        $reservation->setCheckOut(new \DateTimeImmutable('+13 days'));
        $reservation->setGuestName('Připomínaný Host');
        $reservation->setPriceTotal('6000');
        $reservation->setGuestContact(new GuestContact(phone: '776 123 456'));
        $this->em->persist($reservation);

        $action = new ReservationAction($reservation, $type, new \DateTimeImmutable('+5 days'));
        $this->em->persist($action);
        $this->em->flush();

        return $action;
    }

    private function invoice(Reservation $reservation): void
    {
        $invoice = new Invoice('2026077', 2026, 77, InvoiceType::FINAL, $reservation, new \DateTimeImmutable(), new \DateTimeImmutable('+14 days'));
        $invoice->setTotalAmount('4200');
        $invoice->setPdfPath($this->pdf);
        $this->em->persist($invoice);
        $this->em->flush();
    }
}
