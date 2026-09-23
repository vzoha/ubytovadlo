<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\Embeddable\GuestContact;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CustomerControllerTest extends WebTestCase
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

        $this->em->getConnection()->executeStatement('DELETE FROM reservation');
        $this->em->getConnection()->executeStatement('DELETE FROM customer');
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('customer-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'customer-test@example.com']));
    }

    private function stay(string $checkIn, string $name, ?string $email = null, Channel $channel = Channel::WEB): Reservation
    {
        $r = new Reservation($channel, new \DateTimeImmutable($checkIn));
        $r->setCheckOut((new \DateTimeImmutable($checkIn))->modify('+2 days'));
        $r->setStatus(ReservationStatus::COMPLETED);
        $r->setGuestName($name);
        $r->setGuestContact(new GuestContact($email));
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    private function customerOf(Reservation $r): Customer
    {
        $customer = $r->getCustomer();
        self::assertNotNull($customer);

        return $customer;
    }

    public function testListShowsGuestsWithStaysAndSearch(): void
    {
        $this->stay('2025-06-01', 'Jan Novák', 'jan@example.com');
        $this->stay('2026-06-01', 'Jan Novák', 'jan@example.com');
        $this->stay('2026-07-01', 'Eva Malá', 'eva@example.com');

        $crawler = $this->client->request('GET', '/hoste');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'vrací se');

        $crawler = $this->client->request('GET', '/hoste?q=eva@');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Eva Malá');

        $crawler = $this->client->request('GET', '/hoste?q=nikdo');
        self::assertSelectorTextContains('.table-empty', 'Žádný host neodpovídá');
    }

    public function testSuggestionMergeFromList(): void
    {
        $first = $this->stay('2025-06-01', 'Markéta Dvořáková', channel: Channel::AIRBNB);
        $second = $this->stay('2026-06-01', 'Markéta Dvořáková', channel: Channel::AIRBNB);
        $keepId = $this->customerOf($first)->getId();

        $crawler = $this->client->request('GET', '/hoste');
        self::assertSelectorTextContains('.card-header', 'Možná stejný host');

        $this->client->submit($crawler->selectButton('Sloučit')->form());
        self::assertResponseRedirects('/hoste/' . $keepId);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'přesunuto pobytů: 1');

        $this->em->clear();
        self::assertSame($keepId, $this->em->find(Reservation::class, $second->getId())?->getCustomer()?->getId());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM customer'));
    }

    public function testDistinctHidesSuggestionAndReturnsToList(): void
    {
        $this->stay('2025-06-01', 'Markéta Dvořáková', channel: Channel::AIRBNB);
        $this->stay('2026-06-01', 'Markéta Dvořáková', channel: Channel::AIRBNB);

        $crawler = $this->client->request('GET', '/hoste');
        $this->client->submit($crawler->selectButton('Různí lidé')->form());
        self::assertResponseRedirects('/hoste');

        $crawler = $this->client->followRedirect();
        self::assertStringNotContainsString('Možná stejný host', $crawler->text());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM customer'));
    }

    public function testEditNoteShowsOnReservationDetail(): void
    {
        $r = $this->stay('2026-06-01', 'Jan Novák', 'jan@example.com');
        $id = $this->customerOf($r)->getId();

        $crawler = $this->client->request('GET', '/hoste/' . $id);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/hoste/' . $id . '/upravit"]')->form([
            'display_name' => 'Jan Novák st.',
            'note' => 'Jezdí se psem.',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/hoste/' . $id);

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        self::assertStringContainsString('Jezdí se psem.', $crawler->text());
        self::assertCount(1, $crawler->filter('a[href="/hoste/' . $id . '"]'));
    }

    public function testMergeViaModalAndDetachBack(): void
    {
        $a = $this->stay('2025-06-01', 'Jan Novák', 'jan@example.com');
        $b = $this->stay('2026-06-01', 'Jana Nová', 'jana@example.com');
        $keepId = $this->customerOf($a)->getId();
        $mergeId = $this->customerOf($b)->getId();

        $crawler = $this->client->request('GET', '/hoste/' . $keepId);
        $form = $crawler->filter('form[action="/hoste/' . $keepId . '/sloucit"]')->form(['merge_id' => (string) $mergeId]);
        $this->client->submit($form);
        self::assertResponseRedirects('/hoste/' . $keepId);

        $crawler = $this->client->followRedirect();
        self::assertCount(2, $crawler->filter('form[action^="/hoste/pobyt/"]'));

        $this->client->submit($crawler->filter('form[action="/hoste/pobyt/' . $b->getId() . '/oddelit"]')->form());
        self::assertResponseRedirects('/hoste/' . $keepId);

        $this->em->clear();
        $detached = $this->em->find(Reservation::class, $b->getId())?->getCustomer();
        self::assertNotNull($detached);
        self::assertNotSame($keepId, $detached->getId());
        self::assertSame('jana@example.com', $detached->getEmail());
    }

    public function testMutationsRequireCsrf(): void
    {
        $id = $this->customerOf($this->stay('2026-06-01', 'Jan Novák'))->getId();

        $this->client->request('POST', '/hoste/' . $id . '/upravit', ['display_name' => 'X', '_token' => 'bad']);
        self::assertResponseStatusCodeSame(403);
    }
}
