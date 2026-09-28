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
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Enum\ShareChannel;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sdílení faktury odkazem: majitel vytvoří odkaz, host přes něj otevře jen PDF
 * té faktury, a to jen dokud odkaz platí.
 */
final class InvoiceLinkControllerTest extends WebTestCase
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
        $user = new User('invoice-link-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->pdf = sys_get_temp_dir() . '/invoice-link-test.pdf';
        file_put_contents($this->pdf, "%PDF-1.4\n%test\n");
    }

    /** Faktura drží rezervaci přes RESTRICT — po sobě uklízíme, ať ji smažou i ostatní testy. */
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
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
    }

    public function testCreatedLinkServesPdfToAnonymousGuest(): void
    {
        $invoice = $this->invoice();
        $data = $this->createLink($invoice);

        $this->client->restart();
        $this->client->request('GET', $data['url']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertNotNull($this->link()->getLastOpenedAt());
    }

    public function testResponseCarriesMessageTextAndPhoneLinks(): void
    {
        $data = $this->createLink($this->invoice());

        self::assertStringContainsString('fakturu č. 2026099', $data['text']);
        self::assertStringContainsString($data['url'], $data['text']);
        self::assertSame('https://wa.me/420776123456', $data['whatsapp']);
        self::assertSame('sms:+420776123456', $data['sms']);
    }

    /** V databázi je jen otisk — záloha DB neobsahuje funkční odkaz. */
    public function testDatabaseDoesNotStoreToken(): void
    {
        $data = $this->createLink($this->invoice());
        $token = basename($data['url']);

        $stored = $this->em->getConnection()->fetchOne('SELECT token_hash FROM invoice_link');

        self::assertSame(hash('sha256', $token), $stored);
    }

    public function testRevokedLinkIsGone(): void
    {
        $invoice = $this->invoice();
        $data = $this->createLink($invoice);

        $crawler = $this->client->request('GET', '/reservation/' . $invoice->getReservation()->getId());
        $form = $crawler->filter('form[action$="/invoice-link/' . $data['id'] . '/zrusit"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->client->restart();
        $this->client->request('GET', $data['url']);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
    }

    public function testExpiredLinkIsGone(): void
    {
        $data = $this->createLink($this->invoice());
        $this->em->getConnection()->executeStatement('UPDATE invoice_link SET expires_at = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);

        $this->client->restart();
        $this->client->request('GET', $data['url']);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
    }

    public function testUnknownTokenLooksLikeExpiredOne(): void
    {
        $this->client->request('GET', '/f/' . str_repeat('A', 43));

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorTextContains('h1', 'Odkaz na fakturu už neplatí');
    }

    public function testInvoiceWithoutPdfGetsNoLink(): void
    {
        $invoice = $this->invoice(withPdf: false);
        $crawler = $this->login()->request('GET', '/reservation/' . $invoice->getReservation()->getId());

        $this->client->request('POST', '/invoice/' . $invoice->getId() . '/odkaz', ['_token' => $this->modal($crawler, $invoice)->attr('data-text-token')]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice_link'));
    }

    public function testCreateRequiresCsrfToken(): void
    {
        $invoice = $this->invoice();
        $this->login()->request('POST', '/invoice/' . $invoice->getId() . '/odkaz', ['_token' => 'nope']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnonymousCannotCreateLink(): void
    {
        $invoice = $this->invoice();
        $this->client->request('POST', '/invoice/' . $invoice->getId() . '/odkaz');

        self::assertResponseRedirects('/login');
    }

    public function testRecordsChannel(): void
    {
        $invoice = $this->invoice();
        $data = $this->createLink($invoice);
        $crawler = $this->client->request('GET', '/reservation/' . $invoice->getReservation()->getId());

        $this->client->request('POST', $data['sentUrl'], [
            '_token' => $this->modal($crawler, $invoice)->attr('data-link-sent-token'),
            'channel' => 'whatsapp',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(ShareChannel::WHATSAPP, $this->link()->getChannel());
    }

    /** @return array{id: int, url: string, text: string, whatsapp: ?string, sms: ?string, sentUrl: string} */
    private function createLink(Invoice $invoice): array
    {
        $crawler = $this->login()->request('GET', '/reservation/' . $invoice->getReservation()->getId());
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/invoice/' . $invoice->getId() . '/odkaz', ['_token' => $this->modal($crawler, $invoice)->attr('data-text-token')]);
        self::assertResponseIsSuccessful();

        /** @var array{id: int, url: string, text: string, whatsapp: ?string, sms: ?string, sentUrl: string} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data;
    }

    private function modal(Crawler $crawler, Invoice $invoice): Crawler
    {
        return $crawler->filter('#sendInvoice' . $invoice->getId());
    }

    private function login(): KernelBrowser
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'invoice-link-test@example.com']);
        \assert($user instanceof User);
        $this->client->loginUser($user);

        return $this->client;
    }

    private function link(): InvoiceLink
    {
        $this->em->clear();
        $link = $this->em->getRepository(InvoiceLink::class)->findOneBy([]);
        \assert($link instanceof InvoiceLink);

        return $link;
    }

    private function invoice(bool $withPdf = true): Invoice
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('+10 days'));
        $reservation->setCheckOut(new \DateTimeImmutable('+13 days'));
        $reservation->setGuestName('Odkazový Host');
        $reservation->setGuestContact(new GuestContact(phone: '776 123 456'));
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
        $invoice->setPdfPath($withPdf ? $this->pdf : null);
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }
}
