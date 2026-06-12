<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BookingMonthlyInvoice;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class VatControllerTest extends WebTestCase
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

        // Clean DB then seed one Booking reservation + matching invoice.
        $this->em->createQuery('DELETE FROM ' . BookingMonthlyInvoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('vat-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);

        $reservation = new Reservation(Channel::BOOKING, new \DateTimeImmutable('2026-04-13'));
        $reservation->setCheckOut(new \DateTimeImmutable('2026-04-18'));
        $reservation->setStatus(ReservationStatus::CONFIRMED);
        $reservation->setGuestName('Test Host');
        $reservation->setPriceTotal('544.50');
        $reservation->setPriceCurrency('EUR');
        $reservation->setCommissionAmount('82.42');
        $reservation->setCommissionCurrency('EUR');
        $reservation->setVatDuzp(new \DateTimeImmutable('2026-04-30'));
        $reservation->setVatCnbRate('24.36000000');
        $reservation->setVatCnbRateDate(new \DateTimeImmutable('2026-04-30'));
        $reservation->setVatBaseCzk('2007.75');
        $reservation->setVatAmountCzk('421.63');
        $this->em->persist($reservation);

        $invoice = new BookingMonthlyInvoice(
            invoiceNumber: '9999000001',
            issuedAt: new \DateTimeImmutable('2026-05-03'),
            periodFrom: new \DateTimeImmutable('2026-04-01'),
            periodTo: new \DateTimeImmutable('2026-04-30'),
            roomSales: '544.50',
            commission: '82.42',
            totalPayable: '82.42',
            pdfPath: '/tmp/fake.pdf',
        );
        $this->em->persist($invoice);

        $this->em->flush();

        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'vat-test@example.com']));
    }

    public function testListShowsAprilMonth(): void
    {
        $this->client->request('GET', '/dph');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('2026-04', $body);
        self::assertStringContainsString('421,63', $body);
    }

    public function testDetailRendersAllPieces(): void
    {
        $this->client->request('GET', '/dph/2026-04');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('DPH za 2026-04', $body);
        self::assertStringContainsString('2 007,75', $body);
        self::assertStringContainsString('421,63', $body);
        self::assertStringContainsString('#9999000001', $body);
        self::assertStringContainsString('Podání + úhrada na FÚ', $body);
        // Lhůta podání: 25. května
        self::assertStringContainsString('25. 05. 2026', $body);
    }

    public function testDetailShowsMissingReceiptCtaForAirbnbReservation(): void
    {
        $r = $this->seedAirbnbMayReservation();

        $crawler = $this->client->request('GET', '/dph/2026-05');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('chybí doklad', $body);

        $uploadLink = $crawler->filter(sprintf('a[href*="reservation=%d"]', $r->getId()));
        self::assertGreaterThan(0, $uploadLink->count(), 'Chybí odkaz "Nahrát" na předvyplněný upload.');
    }

    public function testUploadFormPrefillsFromReservation(): void
    {
        $r = $this->seedAirbnbMayReservation();

        $crawler = $this->client->request('GET', '/dph/2026-05/airbnb-statement?reservation=' . $r->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(
            (string) $r->getId(),
            $crawler->filter('input[name="airbnb_statement_upload[reservationId]"]')->attr('value'),
        );
        self::assertSame(
            '102.00',
            $crawler->filter('input[name="airbnb_statement_upload[commissionCzk]"]')->attr('value'),
        );

        // Návod ke stažení PDF odkazuje rovnou na konkrétní rezervaci na Airbnb.
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Jak stáhnout PDF', $body);
        self::assertStringContainsString('hosting/stay/HMMNOP56QR', $body);
    }

    public function testUploadStoresPdfAndLinksReservation(): void
    {
        $r = $this->seedAirbnbMayReservation();

        $crawler = $this->client->request('GET', '/dph/2026-05/airbnb-statement?reservation=' . $r->getId());

        $pdf = tempnam(sys_get_temp_dir(), 'air') . '.pdf';
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
        $upload = new UploadedFile($pdf, 'airbnb.pdf', 'application/pdf', null, true);

        $form = $crawler->filter('form')->form();
        $values = $form->getPhpValues();
        $values['airbnb_statement_upload']['pdf'] = $upload;
        $this->client->request('POST', $form->getUri(), $values, ['airbnb_statement_upload' => ['pdf' => $upload]]);

        self::assertResponseRedirects('/dph/2026-05');

        $statement = $this->em->getRepository(\App\Entity\AirbnbStatement::class)
            ->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($statement);
        self::assertSame($r->getId(), $statement->getReservation()?->getId());
        self::assertSame('102.00', $statement->getCommissionCzk());
        self::assertFileExists($statement->getPdfPath());

        @unlink($pdf);
        @unlink($statement->getPdfPath());
    }

    private function seedAirbnbMayReservation(): Reservation
    {
        $r = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('2026-05-28'));
        $r->setCheckOut(new \DateTimeImmutable('2026-05-30'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Eva Marková');
        $r->setExternalId('HMMNOP56QR');
        $r->setCommissionAmount('102.00');
        $r->setCommissionCurrency('CZK');
        $r->setVatBaseCzk('3400.00');
        $r->setVatAmountCzk('714.00');
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }
}
