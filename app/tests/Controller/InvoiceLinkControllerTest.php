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

    public function testWhatsappOpensWithTextAndLinkServesPdfToGuest(): void
    {
        $invoice = $this->invoice();
        $location = $this->send($invoice, 'whatsapp');

        self::assertStringStartsWith('https://wa.me/420776123456?text=', $location);
        $text = $this->textOf($location);
        self::assertStringContainsString('fakturu č. 2026099', $text);

        $this->client->restart();
        $this->client->request('GET', $this->linkIn($text));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertNotNull($this->link()->getLastOpenedAt());
    }

    /** Z nabídky je hned vidět, jestli jde o zálohu, nebo doplatek. */
    public function testMenuSaysWhichInvoiceIsTheBalance(): void
    {
        $invoice = $this->invoice();
        $crawler = $this->login()->request('GET', '/reservation/' . $invoice->getReservation()->getId());

        $labels = $crawler->filter('form[action$="/invoice/' . $invoice->getId() . '/zprava"] button')->each(static fn ($b): string => trim($b->text()));

        self::assertContains('Faktura na doplatek 2026099', $labels);
    }

    public function testSmsOpensWithTextAndRecordsChannel(): void
    {
        $location = $this->send($this->invoice(), 'sms');

        self::assertStringStartsWith('sms:+420776123456?body=', $location);
        self::assertSame(ShareChannel::SMS, $this->link()->getChannel());
    }

    /** Text pro chat se jen připraví — kanál se nezapíše, odeslání appka nevidí. */
    public function testChatGetsTextWithoutRecordingChannel(): void
    {
        $invoice = $this->invoice();
        $this->client->request('POST', '/invoice/' . $invoice->getId() . '/zprava', ['_token' => $this->token($invoice), 'channel' => 'copy']);

        self::assertResponseIsSuccessful();
        /** @var array{text: string} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('/f/', $data['text']);
        self::assertNull($this->link()->getChannel());
    }

    /** V databázi je jen otisk (a bez klíče úložiště žádná zašifrovaná kopie). */
    public function testDatabaseDoesNotStoreToken(): void
    {
        $token = basename($this->linkIn($this->textOf($this->send($this->invoice(), 'whatsapp'))));

        $row = $this->em->getConnection()->fetchAssociative('SELECT token_hash, token_encrypted FROM invoice_link');

        self::assertIsArray($row);
        self::assertSame(hash('sha256', $token), $row['token_hash']);
        self::assertNotSame($token, $row['token_encrypted']);
    }

    public function testRevokedLinkIsGone(): void
    {
        $invoice = $this->invoice();
        $url = $this->linkIn($this->textOf($this->send($invoice, 'whatsapp')));

        $crawler = $this->client->request('GET', '/reservation/' . $invoice->getReservation()->getId());
        $form = $crawler->filter('form[action$="/invoice-link/' . $this->link()->getId() . '/zrusit"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->client->restart();
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
    }

    public function testExpiredLinkIsGone(): void
    {
        $url = $this->linkIn($this->textOf($this->send($this->invoice(), 'whatsapp')));
        $this->em->getConnection()->executeStatement('UPDATE invoice_link SET expires_at = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);

        $this->client->restart();
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
    }

    public function testUnknownTokenLooksLikeExpiredOne(): void
    {
        $this->client->request('GET', '/f/' . str_repeat('A', 43));

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorTextContains('h1', 'Odkaz na fakturu už neplatí');
    }

    /** Faktura bez PDF v menu není a přímý požadavek odkaz nevytvoří. */
    public function testInvoiceWithoutPdfGetsNoLink(): void
    {
        $invoice = $this->invoice(withPdf: false);
        $crawler = $this->login()->request('GET', '/reservation/' . $invoice->getReservation()->getId());
        self::assertCount(0, $crawler->filter('form[action$="/invoice/' . $invoice->getId() . '/zprava"]'));

        $withPdf = $this->invoice(number: '2026100');
        $token = $this->token($withPdf);
        $this->em->getConnection()->executeStatement('UPDATE invoice SET pdf_path = NULL');

        $this->client->request('POST', '/invoice/' . $withPdf->getId() . '/zprava', ['_token' => $token, 'channel' => 'copy']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice_link'));
    }

    public function testRequiresCsrfToken(): void
    {
        $invoice = $this->invoice();
        $this->login()->request('POST', '/invoice/' . $invoice->getId() . '/zprava', ['_token' => 'nope', 'channel' => 'whatsapp']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnonymousCannotCreateLink(): void
    {
        $invoice = $this->invoice();
        $this->client->request('POST', '/invoice/' . $invoice->getId() . '/zprava');

        self::assertResponseRedirects('/login');
    }

    /** Odešle zprávu přes formulář z menu a vrátí cíl přesměrování. */
    private function send(Invoice $invoice, string $channel): string
    {
        $this->client->request('POST', '/invoice/' . $invoice->getId() . '/zprava', ['_token' => $this->token($invoice), 'channel' => $channel]);
        self::assertResponseStatusCodeSame(Response::HTTP_SEE_OTHER);

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /** Token z formuláře v menu „Předvyplnit šablonou" v kartě hosta. */
    private function token(Invoice $invoice): string
    {
        $crawler = $this->login()->request('GET', '/reservation/' . $invoice->getReservation()->getId());
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('form[action$="/invoice/' . $invoice->getId() . '/zprava"] input[name="_token"]')->first()->attr('value');
    }

    private function textOf(string $location): string
    {
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);

        return (string) ($query['text'] ?? $query['body'] ?? '');
    }

    private function linkIn(string $text): string
    {
        self::assertSame(1, preg_match('~https?://\S+/f/[A-Za-z0-9_-]{43}~', $text, $m));

        return $m[0];
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

    private function invoice(bool $withPdf = true, string $number = '2026099'): Invoice
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('+10 days'));
        $reservation->setCheckOut(new \DateTimeImmutable('+13 days'));
        $reservation->setGuestName('Odkazový Host');
        $reservation->setGuestContact(new GuestContact(phone: '776 123 456'));
        $this->em->persist($reservation);

        $invoice = new Invoice(
            $number,
            2026,
            (int) substr($number, 4),
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
