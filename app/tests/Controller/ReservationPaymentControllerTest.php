<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Reservation;
use App\Entity\ReservationReceipt;
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\IncomeSource;
use App\Enum\ReservationStatus;
use App\Repository\ReservationReceiptRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ReservationPaymentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ReservationReceiptRepository $receipts;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->receipts = $container->get(ReservationReceiptRepository::class);

        $this->em->createQuery('DELETE FROM ' . ReservationReceipt::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('payment-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'payment-test@example.com']));
    }

    private function reservation(Channel $channel): Reservation
    {
        $r = new Reservation($channel, new \DateTimeImmutable('+10 days'));
        $r->setCheckOut(new \DateTimeImmutable('+13 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Platební Host');
        $r->setPriceTotal('5000.00');
        $r->setPriceCurrency('CZK');
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    public function testRecordPaymentShowsPartialStatus(): void
    {
        $r = $this->reservation(Channel::DIRECT);

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        $token = (string) $crawler->filter('form[action$="/payment"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/reservation/' . $r->getId() . '/payment', [
            '_token' => $token,
            'amount' => '2000',
            'received_on' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ]);
        self::assertResponseRedirects();

        $receipts = $this->receipts->findForReservation($r);
        self::assertCount(1, $receipts);
        self::assertSame(IncomeSource::MANUAL_PAYMENT, $receipts[0]->getSource());
        self::assertSame('2000.00', $receipts[0]->getAmountCzk());

        $this->client->followRedirect();
        self::assertStringContainsString('Částečně', (string) $this->client->getResponse()->getContent());
    }

    public function testDeletePaymentRemovesReceipt(): void
    {
        $r = $this->reservation(Channel::DIRECT);
        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        $token = (string) $crawler->filter('form[action$="/payment"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/reservation/' . $r->getId() . '/payment', [
            '_token' => $token,
            'amount' => '2000',
        ]);

        $receipt = $this->receipts->findForReservation($r)[0];
        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        $delToken = (string) $crawler->filter('form[action$="/payment/' . $receipt->getId() . '/delete"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/reservation/payment/' . $receipt->getId() . '/delete', ['_token' => $delToken]);
        self::assertResponseRedirects();

        self::assertCount(0, $this->receipts->findForReservation($r));
    }

    public function testOtaReservationHasNoPaymentForm(): void
    {
        $r = $this->reservation(Channel::AIRBNB);
        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());

        // OTA detail nabízí „Reálnou výplatu", ne platbu hosta.
        self::assertCount(0, $crawler->filter('form[action$="/payment"]'));
        self::assertGreaterThan(0, $crawler->filter('form[action$="/payout"]')->count());
    }

    public function testPaymentStatusBadgeInList(): void
    {
        $this->reservation(Channel::DIRECT);
        $this->client->request('GET', '/rezervace');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nezaplaceno', (string) $this->client->getResponse()->getContent());
    }
}
