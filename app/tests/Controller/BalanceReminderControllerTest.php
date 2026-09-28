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
use App\Entity\User;
use App\Enum\ActionDelivery;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Enum\ShareChannel;
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
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
    }

    public function testTextWithUnpaidInvoiceCarriesLink(): void
    {
        $action = $this->reminder();
        $this->invoice($action->getReservation());

        $data = $this->prepare($action);

        self::assertNotNull($data['url']);
        self::assertStringContainsString('připomínáme doplatek', $data['text']);
        self::assertStringContainsString((string) $data['url'], $data['text']);
        self::assertSame('https://wa.me/420776123456', $data['whatsapp']);
        self::assertNotNull($data['sentUrl']);
    }

    public function testTextWithoutInvoiceHasNoLink(): void
    {
        $data = $this->prepare($this->reminder());

        self::assertNull($data['url']);
        self::assertNull($data['sentUrl']);
        self::assertStringContainsString('připomínáme doplatek', $data['text']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice_link'));
    }

    public function testSendingClosesReminderAndItCanBeReopened(): void
    {
        $action = $this->reminder();

        $this->client->request('POST', '/reservation/action/' . $action->getId() . '/odeslano', [
            '_token' => $this->token($action),
            'channel' => ShareChannel::WHATSAPP->value,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $closed = $this->reload($action);
        self::assertSame(ActionStatus::DONE, $closed->getStatus());
        self::assertSame(ActionDelivery::MANUAL, $closed->getDelivery());
        self::assertSame('Odesláno přes WhatsApp.', $closed->getResult());

        $crawler = $this->client->request('GET', '/reservation/' . $closed->getReservation()->getId());
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
        $token = $this->token($action);
        $action->markDone('Odesláno.', ActionDelivery::EMAIL);
        $this->em->flush();

        $this->client->request('POST', '/reservation/action/' . $action->getId() . '/znovu-otevrit', ['_token' => $token]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOtherActionsHaveNoReminderText(): void
    {
        $action = $this->reminder(ActionType::PRE_ARRIVAL_MESSAGE);

        $this->client->request('POST', '/reservation/action/' . $action->getId() . '/pripominka', ['_token' => 'x']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @return array{url: ?string, text: string, whatsapp: ?string, sms: ?string, sentUrl: ?string} */
    private function prepare(ReservationAction $action): array
    {
        $this->client->request('POST', '/reservation/action/' . $action->getId() . '/pripominka', ['_token' => $this->token($action)]);
        self::assertResponseIsSuccessful();

        /** @var array{url: ?string, text: string, whatsapp: ?string, sms: ?string, sentUrl: ?string} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data;
    }

    /** Token z okna „Poslat připomínku doplatku" v detailu rezervace. */
    private function token(ReservationAction $action): string
    {
        $crawler = $this->client->request('GET', '/reservation/' . $action->getReservation()->getId());
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('#sendReminder' . $action->getId())->attr('data-text-token');
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
        $reservation = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('+10 days'));
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
