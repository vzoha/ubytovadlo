<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AirbnbStatement;
use App\Entity\BookingMonthlyInvoice;
use App\Entity\CheckinEvent;
use App\Entity\Cleaning;
use App\Entity\GuestDocument;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Nationality;
use App\Entity\Reservation;
use App\Entity\Setting;
use App\Entity\User;
use App\Entity\VatPeriod;
use App\Enum\Channel;
use App\Enum\CheckinStep;
use App\Repository\CheckinEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Kroky hosta v online check-inu se zapisují pro průběh na časové ose.
 */
final class CheckinProgressTest extends WebTestCase
{
    private const string PHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Reservation $reservation;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTP_ACCEPT_LANGUAGE' => 'cs', 'HTTP_USER_AGENT' => self::PHONE_UA]);
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        foreach ([CheckinEvent::class, InvoiceLine::class, Invoice::class, AirbnbStatement::class, BookingMonthlyInvoice::class,
            VatPeriod::class, Cleaning::class, GuestDocument::class, Reservation::class, Setting::class, User::class] as $class) {
            $this->em->createQuery('DELETE FROM ' . $class . ' x')->execute();
        }

        if ($this->em->getRepository(Nationality::class)->find('DEU') === null) {
            $this->em->persist(new Nationality('DEU', 'Spolková republika Německo', 'Germany'));
            $this->em->persist(new Nationality('SVK', 'Slovenská republika', 'Slovakia'));
        }

        $this->reservation = new Reservation(Channel::BOOKING, new \DateTimeImmutable('+5 days'));
        $this->reservation->setCheckOut(new \DateTimeImmutable('+8 days'));
        $this->reservation->setGuestsAdult(2);
        $this->em->persist($this->reservation);
        $this->em->flush();
    }

    public function testRepeatedVisitWithinPauseCountsOnce(): void
    {
        $this->client->request('GET', $this->url());
        $this->client->request('GET', $this->url());

        $events = $this->events();
        self::assertCount(1, $events);
        self::assertSame(CheckinStep::VISIT, $events[0]->getStep());
        self::assertSame(['device' => 'mobile'], $events[0]->getDetails());
    }

    public function testSavedHostCarriesPrefillSource(): void
    {
        $crawler = $this->client->request('GET', $this->url('/host/novy'));
        $form = $crawler->selectButton('Uložit')->form($this->foreignerFields() + ['checkin_prefill' => 'live']);
        $this->client->submit($form);

        self::assertResponseRedirects($this->url());
        $steps = array_map(static fn (CheckinEvent $e) => $e->getStep(), $this->events());
        self::assertSame([CheckinStep::HOST_FORM, CheckinStep::HOST_SAVED], $steps);
        self::assertSame(['new' => true, 'prefill' => 'live'], $this->events()[1]->getDetails());
    }

    public function testUnknownPrefillSourceIsDropped(): void
    {
        $crawler = $this->client->request('GET', $this->url('/host/novy'));
        $form = $crawler->selectButton('Uložit')->form($this->foreignerFields() + ['checkin_prefill' => '<script>']);
        $this->client->submit($form);

        self::assertNull($this->events()[1]->getDetails()['prefill']);
    }

    public function testInvalidSubmitRecordsFieldNamesWithoutValues(): void
    {
        $crawler = $this->client->request('GET', $this->url('/host/novy'));
        $form = $crawler->selectButton('Uložit')->form([
            'guest_document[lastName]' => 'Smith',
            'guest_document[firstName]' => 'John',
            'guest_document[birthDate]' => '1985-03-15',
        ]);
        $this->client->submit($form);

        $invalid = $this->events()[1];
        self::assertSame(CheckinStep::HOST_INVALID, $invalid->getStep());
        $fields = $invalid->getDetails()['fields'];
        self::assertNotEmpty($fields);
        self::assertNotContains('lastName', $fields);
        self::assertStringNotContainsString('Smith', (string) json_encode($invalid->getDetails()));
    }

    public function testDocumentReadRecordsSourceAndResult(): void
    {
        $mrz = "P<D<<MUELLER<<HANS<<<<<<<<<<<<<<<<<<<<<<<<<<<\nC01X00T478D<<6408125M2010315<<<<<<<<<<<<<<04";
        $this->postMrz(['mrz' => $mrz, 'source' => 'manual']);
        $this->postMrz(['mrz_texts' => ['garbage'], 'source' => 'photo']);

        $events = $this->events();
        self::assertCount(2, $events);
        self::assertSame('manual', $events[0]->getDetails()['source']);
        self::assertTrue($events[0]->getDetails()['ok']);
        self::assertSame('photo', $events[1]->getDetails()['source']);
        self::assertFalse($events[1]->getDetails()['ok']);
        self::assertArrayNotHasKey('lastName', $events[0]->getDetails());
    }

    public function testLoggedInOwnerIsNotRecorded(): void
    {
        $user = new User('checkin-progress@example.com');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', $this->url());

        self::assertSame([], $this->events());
    }

    public function testCompletedCheckinVisitIsNotRecorded(): void
    {
        $this->reservation->markCheckinCompleted();
        $this->em->flush();

        $this->client->request('GET', $this->url());

        self::assertSame([], $this->events());
    }

    private function url(string $suffix = ''): string
    {
        return '/checkin/' . $this->reservation->getCheckinToken() . $suffix;
    }

    /** @param array<string, mixed> $payload */
    private function postMrz(array $payload): void
    {
        $this->client->request('POST', $this->url('/mrz'), [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($payload));
    }

    /** @return array<string, string> */
    private function foreignerFields(): array
    {
        return [
            'guest_document[lastName]' => 'Muster',
            'guest_document[firstName]' => 'Erika',
            'guest_document[birthDate]' => '1980-04-16',
            'guest_document[nationality]' => 'DEU',
            'guest_document[documentType]' => 'passport',
            'guest_document[documentNumber]' => 'C01X00T47',
            'guest_document[residenceAddress]' => 'Musterstrasse 1, Berlin',
        ];
    }

    /** @return CheckinEvent[] */
    private function events(): array
    {
        $this->em->clear();

        return static::getContainer()->get(CheckinEventRepository::class)->findBy([], ['id' => 'ASC']);
    }
}
