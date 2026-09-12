<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * QR kód k faktuře je veřejný (jede jako obrázek v e-mailu hostovi), autorizuje
 * ho check-in token rezervace — cizí doklad přes něj vidět není.
 */
final class QrInvoiceControllerTest extends WebTestCase
{
    /** Check-in token je 64 hex znaků — v testu si ho poskládáme, ať v repu nevypadá jako tajemství. */
    private const TOKEN_UNIT = '0123456789abcdef';
    private const OTHER_TOKEN_UNIT = 'fedcba9876543210';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->clearInvoices();
    }

    /** Faktura drží rezervaci přes RESTRICT — po sobě uklízíme, ať ji smažou i ostatní testy. */
    protected function tearDown(): void
    {
        $this->clearInvoices();

        parent::tearDown();
    }

    private function clearInvoices(): void
    {
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
    }

    public function testServesPngForOwnToken(): void
    {
        $invoice = $this->invoice('SPD*1.0*ACC:CZ6508000000001861547133*AM:4200.00*CC:CZK');

        $this->client->request('GET', sprintf('/qr/faktura/%s/%d.png', $this->token(self::TOKEN_UNIT), (int) $invoice->getId()));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
    }

    public function testRejectsForeignToken(): void
    {
        $invoice = $this->invoice('SPD*1.0*ACC:CZ6508000000001861547133*AM:4200.00*CC:CZK');

        $this->client->request('GET', sprintf('/qr/faktura/%s/%d.png', $this->token(self::OTHER_TOKEN_UNIT), (int) $invoice->getId()));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testMissingPayloadIsNotFound(): void
    {
        $invoice = $this->invoice(null);

        $this->client->request('GET', sprintf('/qr/faktura/%s/%d.png', $this->token(self::TOKEN_UNIT), (int) $invoice->getId()));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function token(string $unit): string
    {
        return str_repeat($unit, 4);
    }

    private function invoice(?string $payload): Invoice
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('+10 days'));
        $reservation->setCheckOut(new \DateTimeImmutable('+13 days'));
        $reservation->setGuestName('Fakturační Host');
        $reservation->setCheckinToken($this->token(self::TOKEN_UNIT));
        $this->em->persist($reservation);

        $invoice = new Invoice(
            '2026099',
            2026,
            99,
            InvoiceType::FINAL,
            $reservation,
            new \DateTimeImmutable(),
            new \DateTimeImmutable('+14 days'),
        );
        $invoice->setTotalAmount('4200');
        $invoice->setQrPayload($payload);
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }
}
