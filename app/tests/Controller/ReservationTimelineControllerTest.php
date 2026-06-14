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
use App\Entity\ReservationAction;
use App\Entity\ReservationNote;
use App\Entity\User;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Repository\ReservationActionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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

        $this->em->createQuery('DELETE FROM ' . ReservationAction::class . ' a')->execute();
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
