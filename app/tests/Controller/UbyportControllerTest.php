<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccommodationProfile;
use App\Entity\GuestDocument;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\Channel;
use App\Repository\ReservationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UbyportControllerTest extends WebTestCase
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

        $this->em->createQuery('DELETE FROM ' . GuestDocument::class . ' g')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . AccommodationProfile::class . ' p')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();
        $this->em->flush();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('ubyport@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'ubyport@example.com']));
    }

    private function persistProfile(): void
    {
        $p = new AccommodationProfile();
        $p->setIdub('123456789012');
        $p->setKod('VODPO');
        $p->setNazev('Hotel Pošta');
        $p->setSpojeni('Jan Sibelius');
        $p->setOkres('Strakonice');
        $p->setObec('Vodňany');
        $p->setUlice('Alešova');
        $p->setCp('26');
        $p->setPsc('38901');
        $this->em->persist($p);
        $this->em->flush();
    }

    private function persistForeigner(bool $complete = true, string $checkIn = '2026-06-15'): Reservation
    {
        $r = new Reservation(Channel::BOOKING, new \DateTimeImmutable($checkIn));
        $r->setCheckOut((new \DateTimeImmutable($checkIn))->modify('+3 days'));
        $this->em->persist($r);

        $doc = new GuestDocument($r, 'Hans', 'Müller', new \DateTimeImmutable('1980-04-16'));
        $doc->setNationalityCode('DEU');
        if ($complete) {
            $doc->setDocumentNumber('AB1234567');
        }
        $doc->confirm();
        $this->em->persist($doc);
        $this->em->flush();

        return $r;
    }

    private function reload(int $id): Reservation
    {
        $this->em->clear();

        return static::getContainer()->get(ReservationRepository::class)->find($id);
    }

    public function testIndexRendersQueue(): void
    {
        $this->persistProfile();
        $this->persistForeigner();

        $crawler = $this->client->request('GET', '/ubyport');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ubyport');
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Müller', $body);
        self::assertGreaterThan(0, $crawler->filter('form[action$="/export"]')->count(), 'kompletní rezervace má tlačítko Stáhnout UNL');
    }

    public function testOverdueShowsDeadlineAlert(): void
    {
        $this->persistProfile();
        $this->persistForeigner(checkIn: (new \DateTimeImmutable('-10 days'))->format('Y-m-d'));

        $this->client->request('GET', '/ubyport');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('po zákonné lhůtě', $body);
        self::assertStringContainsString('deadline-overdue', $body);
    }

    public function testIncompleteReservationListedSeparately(): void
    {
        $this->persistProfile();
        $this->persistForeigner(complete: false);

        $this->client->request('GET', '/ubyport');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Neúplné', $body);
        self::assertStringContainsString('číslo dokladu', $body);
    }

    public function testExportMarksReservationExported(): void
    {
        $this->persistProfile();
        $r = $this->persistForeigner();
        $rid = $r->getId();

        $crawler = $this->client->request('GET', '/ubyport');
        $token = $crawler->filter('form[action$="/ubyport/' . $rid . '/export"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/ubyport/' . $rid . '/export', ['_token' => $token]);

        $response = $this->client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('windows-1250', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('.unl', (string) $response->headers->get('Content-Disposition'));

        self::assertNotNull($this->reload($rid)->getUbyportExportedAt(), 'export musí označit rezervaci jako exportovanou');
    }

    public function testExportedReservationWaitsForReceipt(): void
    {
        $this->persistProfile();
        $r = $this->persistForeigner();
        $rid = $r->getId();

        $crawler = $this->client->request('GET', '/ubyport');
        $token = $crawler->filter('form[action$="/ubyport/' . $rid . '/export"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/ubyport/' . $rid . '/export', ['_token' => $token]);

        $crawler = $this->client->request('GET', '/ubyport');
        self::assertStringContainsString('čeká na doručenku', (string) $this->client->getResponse()->getContent());
        self::assertGreaterThan(0, $crawler->filter('form[action$="/ubyport/' . $rid . '/dorucenka"]')->count(), 'nabízí upload doručenky');
    }

    public function testManualConfirmMarksReported(): void
    {
        $this->persistProfile();
        $r = $this->persistForeigner();
        $rid = $r->getId();
        $r->markUbyportExported(new \DateTimeImmutable());
        $this->em->flush();

        $crawler = $this->client->request('GET', '/ubyport');
        $token = $crawler->filter('form[action$="/ubyport/' . $rid . '/oznacit"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/ubyport/' . $rid . '/oznacit', ['_token' => $token]);

        self::assertResponseRedirects('/ubyport');
        self::assertNotNull($this->reload($rid)->getUbyportConfirmedAt(), 'ruční potvrzení označí jako nahlášené');
    }

    public function testUnreportReturnsReservationToQueue(): void
    {
        $this->persistProfile();
        $r = $this->persistForeigner();
        $rid = $r->getId();
        $r->confirmUbyportReported(new \DateTimeImmutable());
        $this->em->flush();

        $crawler = $this->client->request('GET', '/ubyport');
        $token = $crawler->filter('form[action$="/ubyport/' . $rid . '/vratit"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/ubyport/' . $rid . '/vratit', ['_token' => $token]);

        self::assertResponseRedirects('/ubyport');
        $reloaded = $this->reload($rid);
        self::assertNull($reloaded->getUbyportConfirmedAt(), 'vrátit zpět = smazat potvrzení');
        self::assertNull($reloaded->getUbyportExportedAt(), 'vrátit zpět = smazat i export');
    }
}
