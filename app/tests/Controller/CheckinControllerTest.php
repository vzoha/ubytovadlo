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
use App\Entity\Cleaning;
use App\Entity\GuestDocument;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Nationality;
use App\Entity\Reservation;
use App\Entity\Setting;
use App\Entity\VatPeriod;
use App\Enum\Channel;
use App\Repository\GuestDocumentRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CheckinControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Reservation $reservation;

    protected function setUp(): void
    {
        // Český host: prohlížeč hlásí cs, takže check-in vyjde česky a test
        // ověřuje logiku proti českým textům. Vícejazyčnost řeší CheckinLocaleTest.
        $this->client = static::createClient([], ['HTTP_ACCEPT_LANGUAGE' => 'cs']);
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->createQuery('DELETE FROM ' . InvoiceLine::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . AirbnbStatement::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . BookingMonthlyInvoice::class . ' b')->execute();
        $this->em->createQuery('DELETE FROM ' . VatPeriod::class . ' v')->execute();
        $this->em->createQuery('DELETE FROM ' . Cleaning::class . ' c')->execute();
        $this->em->createQuery('DELETE FROM ' . GuestDocument::class . ' g')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s')->execute();
        $this->em->flush();

        if ($this->em->getRepository(Nationality::class)->find('DEU') === null) {
            $this->em->persist(new Nationality('DEU', 'Spolková republika Německo', 'Germany'));
            $this->em->persist(new Nationality('SVK', 'Slovenská republika', 'Slovakia'));
            $this->em->flush();
        }

        $this->reservation = new Reservation(Channel::BOOKING, new \DateTimeImmutable('2026-06-15'));
        $this->reservation->setCheckOut(new \DateTimeImmutable('2026-06-18'));
        $this->reservation->setGuestsAdult(2);
        $this->em->persist($this->reservation);
        $this->em->flush();
    }

    public function testIndexRendersForValidToken(): void
    {
        $this->client->request('GET', '/checkin/' . $this->reservation->getCheckinToken());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Online check-in');
    }

    public function testInvalidTokenReturns404(): void
    {
        $this->client->request('GET', '/checkin/' . str_repeat('0', 64));

        self::assertResponseStatusCodeSame(404);
    }

    public function testMalformedTokenReturns404(): void
    {
        $this->client->request('GET', '/checkin/not-hex');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAddForeignerPersistsDocument(): void
    {
        $token = $this->reservation->getCheckinToken();
        $crawler = $this->client->request('GET', '/checkin/' . $token . '/host/novy');

        $form = $crawler->selectButton('Uložit')->form([
            'guest_document[lastName]' => 'Müller',
            'guest_document[firstName]' => 'Günter',
            'guest_document[birthDate]' => '1980-04-16',
            'guest_document[nationality]' => 'DEU',
            'guest_document[documentType]' => 'passport',
            'guest_document[documentNumber]' => 'AB1234567',
            'guest_document[residenceAddress]' => 'Berlin, Vogelstrasse 45',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/checkin/' . $token);

        $docs = static::getContainer()->get(GuestDocumentRepository::class)->findByReservation($this->reservation);
        self::assertCount(1, $docs);
        $g = $docs[0];
        self::assertSame('Müller', $g->getLastName());
        self::assertSame('DEU', $g->getNationalityCode());
        self::assertSame('AB1234567', $g->getDocumentNumber());
        self::assertFalse($g->isCzechCitizen());
        self::assertNotNull($g->getConfirmedAt(), 'submit musi confirm() automaticky');
    }

    public function testCzechCitizenKeepsDocumentButClearsNationality(): void
    {
        // Výchozí stav (bez nastavení) = evidujeme i Čechy → doklad a adresa do
        // evidenční knihy zůstávají, maže se jen cizinecké pole (občanství).
        $token = $this->reservation->getCheckinToken();
        $crawler = $this->client->request('GET', '/checkin/' . $token . '/host/novy');

        $form = $crawler->selectButton('Uložit')->form([
            'guest_document[lastName]' => 'Novák',
            'guest_document[firstName]' => 'Jan',
            'guest_document[birthDate]' => '1985-01-01',
            'guest_document[isCzechCitizen]' => '1',
            'guest_document[nationality]' => 'DEU',
            'guest_document[documentType]' => 'id_card',
            'guest_document[documentNumber]' => '123456789',
            'guest_document[residenceAddress]' => 'Dlouhá 5, Praha',
        ]);
        $this->client->submit($form);

        $docs = static::getContainer()->get(GuestDocumentRepository::class)->findByReservation($this->reservation);
        self::assertCount(1, $docs);
        $g = $docs[0];
        self::assertTrue($g->isCzechCitizen());
        self::assertNull($g->getNationalityCode(), 'u Čecha se občanství (Ubyport) maže');
        self::assertSame('123456789', $g->getDocumentNumber(), 'doklad Čecha zůstává v evidenční knize');
        self::assertSame('Dlouhá 5, Praha', $g->getResidenceAddress(), 'adresa Čecha zůstává');
    }

    public function testCzechCitizenClearsDocumentWhenRegistrationOff(): void
    {
        // Vypnutá evidence Čechů → čistě český host je jen jméno + datum narození,
        // doklad i adresa se zahodí (jako dřív).
        $this->em->persist(new Setting('guestbook.register_czech', '0'));
        $this->em->flush();

        $token = $this->reservation->getCheckinToken();
        $crawler = $this->client->request('GET', '/checkin/' . $token . '/host/novy');

        $form = $crawler->selectButton('Uložit')->form([
            'guest_document[lastName]' => 'Novák',
            'guest_document[firstName]' => 'Jan',
            'guest_document[birthDate]' => '1985-01-01',
            'guest_document[isCzechCitizen]' => '1',
            'guest_document[nationality]' => 'DEU',
            'guest_document[documentType]' => 'id_card',
            'guest_document[documentNumber]' => 'ZAPOMENU',
            'guest_document[residenceAddress]' => 'Praha',
        ]);
        $this->client->submit($form);

        $docs = static::getContainer()->get(GuestDocumentRepository::class)->findByReservation($this->reservation);
        self::assertCount(1, $docs);
        $g = $docs[0];
        self::assertTrue($g->isCzechCitizen());
        self::assertNull($g->getNationalityCode());
        self::assertNull($g->getDocumentType(), 'bez evidence Čechů se doklad maže');
        self::assertNull($g->getDocumentNumber());
        self::assertNull($g->getResidenceAddress());
    }

    public function testEditUpdatesExistingDocument(): void
    {
        $doc = new GuestDocument($this->reservation, 'Alt', 'Alt', new \DateTimeImmutable('1990-01-01'));
        $doc->setNationalityCode('SVK');
        $doc->setDocumentNumber('OLD');
        $this->em->persist($doc);
        $this->em->flush();
        $docId = $doc->getId();
        $this->em->clear();

        $token = $this->reservation->getCheckinToken();
        $crawler = $this->client->request('GET', '/checkin/' . $token . '/host/' . $docId);

        $form = $crawler->selectButton('Uložit')->form([
            'guest_document[lastName]' => 'New',
            'guest_document[firstName]' => 'New',
            'guest_document[birthDate]' => '1990-01-01',
            'guest_document[nationality]' => 'DEU',
            'guest_document[documentType]' => 'passport',
            'guest_document[documentNumber]' => 'NEW',
            'guest_document[residenceAddress]' => 'Wien, Ringstrasse 1',
        ]);
        $this->client->submit($form);

        $repo = static::getContainer()->get(GuestDocumentRepository::class);
        self::assertCount(1, $repo->findByReservation($this->reservation), 'nesmi vzniknout duplikat');
        $updated = $repo->find($docId);
        self::assertSame('New', $updated->getLastName());
        self::assertSame('DEU', $updated->getNationalityCode());
        self::assertSame('NEW', $updated->getDocumentNumber());
    }

    public function testEditGuestFromDifferentReservationReturns404(): void
    {
        $other = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-07-01'));
        $other->setCheckOut(new \DateTimeImmutable('2026-07-03'));
        $this->em->persist($other);
        $doc = new GuestDocument($other, 'X', 'X', new \DateTimeImmutable('1990-01-01'));
        $this->em->persist($doc);
        $this->em->flush();

        $this->client->request('GET', '/checkin/' . $this->reservation->getCheckinToken() . '/host/' . $doc->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testFinishMarksReservationAndRedirects(): void
    {
        $doc = new GuestDocument($this->reservation, 'X', 'X', new \DateTimeImmutable('1990-01-01'));
        $this->em->persist($doc);
        $this->em->flush();

        $token = $this->reservation->getCheckinToken();
        $crawler = $this->client->request('GET', '/checkin/' . $token);

        $form = $crawler->filter('form[action$="/hotovo"] button[type="submit"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/checkin/' . $token . '/dekujeme');

        $reservation = static::getContainer()->get(ReservationRepository::class)->find($this->reservation->getId());
        self::assertNotNull($reservation->getCheckinCompletedAt());
    }

    public function testFinishWithNoGuestsCompletesForCzechOnlyStay(): void
    {
        // S vypnutou evidencí Čechů projde čistě česká skupina bez dokladů —
        // rozcestník nabídne „Ne, jsme všichni Češi — dokončit".
        $this->em->persist(new Setting('guestbook.register_czech', '0'));
        $this->em->flush();

        $token = $this->reservation->getCheckinToken();

        $crawler = $this->client->request('GET', '/checkin/' . $token);
        $csrfToken = $crawler->filter('form[action$="/hotovo"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/checkin/' . $token . '/hotovo', [
            '_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/checkin/' . $token . '/dekujeme');

        $this->em->clear();
        $reservation = static::getContainer()->get(ReservationRepository::class)->find($this->reservation->getId());
        self::assertNotNull($reservation->getCheckinCompletedAt(), 'česká skupina smí uzavřít bez doklad');
    }

    public function testForeignerWithoutRequiredFieldsShowsErrors(): void
    {
        $token = $this->reservation->getCheckinToken();
        $crawler = $this->client->request('GET', '/checkin/' . $token . '/host/novy');

        $form = $crawler->selectButton('Uložit')->form([
            'guest_document[lastName]' => 'Smith',
            'guest_document[firstName]' => 'John',
            'guest_document[birthDate]' => '1985-03-15',
        ]);
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.invalid-feedback, .form-error-message');

        $docs = static::getContainer()->get(GuestDocumentRepository::class)->findByReservation($this->reservation);
        self::assertCount(0, $docs, 'nesmí uložit cizince bez povinných polí');
    }

    public function testEditingClosedCheckinReturns404(): void
    {
        $this->reservation->markCheckinCompleted();
        $this->em->flush();

        $this->client->request('GET', '/checkin/' . $this->reservation->getCheckinToken() . '/host/novy');

        self::assertResponseStatusCodeSame(404);
    }

    public function testIndexShowsCompletedStateReadOnly(): void
    {
        $this->reservation->markCheckinCompleted();
        $this->em->flush();

        $crawler = $this->client->request('GET', '/checkin/' . $this->reservation->getCheckinToken());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-success', 'Check-in byl uzavřen');
        self::assertCount(0, $crawler->filter('a:contains("Přidat hosta")'));
    }

    public function testMrzEndpointParsesPassport(): void
    {
        $token = $this->reservation->getCheckinToken();
        $mrz = "P<D<<MUELLER<<HANS<<<<<<<<<<<<<<<<<<<<<<<<<<<\nC01X00T478D<<6408125M2010315<<<<<<<<<<<<<<04";

        $this->client->request('POST', '/checkin/' . $token . '/mrz', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['mrz' => $mrz]));

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('MUELLER', $data['lastName']);
        self::assertSame('HANS', $data['firstName']);
        self::assertSame('1964-08-12', $data['birthDate']);
        self::assertSame('DEU', $data['nationalityCode']);
        self::assertSame('passport', $data['documentType']);
        self::assertTrue($data['nationalityFound']);
    }

    public function testMrzEndpointReturns422ForGarbage(): void
    {
        $token = $this->reservation->getCheckinToken();

        $this->client->request('POST', '/checkin/' . $token . '/mrz', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['mrz' => 'not a valid mrz']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testMrzEndpointReturns400ForEmptyBody(): void
    {
        $token = $this->reservation->getCheckinToken();

        $this->client->request('POST', '/checkin/' . $token . '/mrz', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        self::assertResponseStatusCodeSame(400);
    }

    public function testMrzEndpointRejects404ForInvalidToken(): void
    {
        $this->client->request('POST', '/checkin/' . str_repeat('0', 64) . '/mrz', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['mrz' => 'P<test']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testIndexOffersBillingWhenAddressMissing(): void
    {
        $this->client->request('GET', '/checkin/' . $this->reservation->getCheckinToken());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href$="/fakturace"]');
        self::assertSelectorTextContains('body', 'evidenční knihy');
    }

    public function testBillingFormSavesObjednatel(): void
    {
        $token = $this->reservation->getCheckinToken();
        $crawler = $this->client->request('GET', '/checkin/' . $token . '/fakturace');

        $form = $crawler->selectButton('Uložit')->form([
            'checkin_billing[guestName]' => 'Jan Novák',
            'checkin_billing[guestAddress][street]' => 'Dlouhá 5',
            'checkin_billing[guestAddress][zip]' => '110 00',
            'checkin_billing[guestAddress][city]' => 'Praha',
            'checkin_billing[guestBilling][companyName]' => 'Firma s.r.o.',
            'checkin_billing[guestBilling][ico]' => '12345678',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/checkin/' . $token);

        $this->em->clear();
        $reservation = static::getContainer()->get(ReservationRepository::class)->find($this->reservation->getId());
        self::assertSame('Jan Novák', $reservation->getGuestName());
        self::assertSame('Dlouhá 5', $reservation->getGuestAddress()->getStreet());
        self::assertSame('Firma s.r.o.', $reservation->getGuestBilling()->getCompanyName());
        self::assertSame('12345678', $reservation->getGuestBilling()->getIco());

        // Po doplnění už index billing nenabízí jako chybějící.
        $this->client->request('GET', '/checkin/' . $token);
        self::assertSelectorTextContains('body', 'Fakturační údaje máme vyplněné');
    }
}
