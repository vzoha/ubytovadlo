<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Email\GuestInbox\MailAddress;
use App\Entity\Embeddable\GuestContact;
use App\Entity\GuestEmail;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\EmailDirection;
use App\Mail\MailSettingsProvider;
use App\Repository\GuestEmailRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class GuestEmailControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        foreach (['guest_email', 'reservation', 'customer', 'app_user'] as $table) {
            $em->getConnection()->executeStatement('DELETE FROM ' . $table);
        }

        $user = new User('mail@example.com');
        $user->setPassword('x');
        $user->setRoles(['ROLE_ADMIN']);
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user);
    }

    public function testOwnerAssignsEmailToStayFromCustomerDetail(): void
    {
        $this->stay('jana@example.com', '2030-07-12');
        $december = $this->stay('jana@example.com', '2030-12-20');
        $email = $this->email($december);

        $crawler = $this->client->request('GET', '/hoste/' . $december->getCustomer()?->getId());
        $form = $crawler->selectButton('Přiřadit k pobytu')->form(['reservation' => (string) $december->getId()]);
        $this->client->submit($form);

        self::assertResponseRedirects('/hoste/' . $december->getCustomer()?->getId());
        $saved = static::getContainer()->get(GuestEmailRepository::class)->find($email->getId());
        self::assertSame($december->getId(), $saved?->getReservation()?->getId());
    }

    public function testStayOfAnotherGuestIsRejected(): void
    {
        $own = $this->stay('jana@example.com', '2030-07-12');
        $foreign = $this->stay('petr@example.com', '2030-08-01');
        $email = $this->email($own);

        $crawler = $this->client->request('GET', '/hoste/' . $own->getCustomer()?->getId());
        $token = (string) $crawler->filter('form[action$="/rezervace"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/e-maily-hostu/' . $email->getId() . '/rezervace', ['_token' => $token, 'reservation' => $foreign->getId()]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testOwnerRepliesFromCustomerDetail(): void
    {
        $stay = $this->stay('jana@example.com', '2030-07-12');
        $this->email($stay);
        static::getContainer()->get(SettingRepository::class)->set(MailSettingsProvider::SENDER_EMAIL, 'info@example.com');
        $this->em->flush();
        $detail = '/hoste/' . $stay->getCustomer()?->getId();

        $crawler = $this->client->request('GET', $detail);
        $button = $crawler->selectButton('Odpovědět');
        $this->client->request('POST', (string) $button->attr('data-reply-url'), [
            '_token' => $button->attr('data-reply-token'),
            'back' => $detail,
            'subject' => (string) $button->attr('data-reply-subject'),
            'text' => "Dobrý den,\n\nano, máme volno.",
        ]);

        self::assertResponseRedirects($detail);
        self::assertEmailCount(1);
        $sent = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $sent);
        self::assertSame('Re: Dotaz', $sent->getSubject());
    }

    private function stay(string $address, string $checkIn): Reservation
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable($checkIn));
        $reservation->setCheckOut((new \DateTimeImmutable($checkIn))->modify('+3 days'));
        $reservation->setGuestName('Testovací host');
        $reservation->setGuestContact(new GuestContact($address));
        $this->em->persist($reservation);
        $this->em->flush();

        return $reservation;
    }

    private function email(Reservation $ofGuest): GuestEmail
    {
        $email = new GuestEmail(EmailDirection::INBOUND, 'assign-1@example.com', new MailAddress(null, 'jana@example.com'), 'Dotaz', new \DateTimeImmutable('2030-06-01'), 'Dobrý den');
        $email->completeMatch($ofGuest->getCustomer(), null);
        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }
}
