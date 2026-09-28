<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Credential\CredentialCipher;
use App\Entity\Invoice;
use App\Entity\InvoiceLink;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Invoice\InvoiceLinks;
use App\Repository\InvoiceLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Platný odkaz se posílá znovu, nový vzniká až po vypršení nebo zrušení. */
#[AllowMockObjectsWithoutExpectations]
final class InvoiceLinksTest extends TestCase
{
    private CredentialCipher $cipher;
    private MockClock $clock;
    /** @var list<InvoiceLink> */
    private array $stored = [];

    protected function setUp(): void
    {
        $this->cipher = new CredentialCipher(base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        $this->clock = new MockClock(new \DateTimeImmutable('2026-09-28 12:00'));
    }

    public function testReusesActiveLink(): void
    {
        $links = $this->links();
        $invoice = $this->invoice();

        $first = $links->obtain($invoice);
        $second = $links->obtain($invoice);

        self::assertSame($first->url, $second->url);
        self::assertCount(1, $this->stored);
    }

    public function testIssuesNewLinkAfterRevocation(): void
    {
        $links = $this->links();
        $invoice = $this->invoice();

        $first = $links->obtain($invoice);
        $first->link->revoke($this->clock->now());
        $second = $links->obtain($invoice);

        self::assertNotSame($first->url, $second->url);
        self::assertCount(2, $this->stored);
    }

    public function testIssuesNewLinkAfterExpiry(): void
    {
        $links = $this->links();
        $invoice = $this->invoice();

        $first = $links->obtain($invoice);
        $this->clock->modify('+' . (InvoiceLinks::LIFETIME_DAYS + 1) . ' days');

        self::assertNotSame($first->url, $links->obtain($invoice)->url);
    }

    public function testEncryptedTokenIsNotPlain(): void
    {
        $issued = $this->links()->obtain($this->invoice());
        $token = basename($issued->url);

        self::assertNotNull($issued->link->getTokenEncrypted());
        self::assertStringNotContainsString($token, (string) $issued->link->getTokenEncrypted());
        self::assertSame($token, $this->cipher->decrypt((string) $issued->link->getTokenEncrypted()));
    }

    /** Bez klíče úložiště se token neuloží — každé odeslání dostane nový odkaz. */
    public function testWithoutKeyEveryLinkIsNew(): void
    {
        $this->cipher = new CredentialCipher('');
        $links = $this->links();
        $invoice = $this->invoice();

        self::assertNotSame($links->obtain($invoice)->url, $links->obtain($invoice)->url);
    }

    private function links(): InvoiceLinks
    {
        $repository = $this->createMock(InvoiceLinkRepository::class);
        $repository->method('findForInvoice')->willReturnCallback(fn (): array => array_reverse($this->stored));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $link): void {
            \assert($link instanceof InvoiceLink);
            $this->stored[] = $link;
        });

        $url = $this->createStub(UrlGeneratorInterface::class);
        $url->method('generate')->willReturnCallback(static fn (string $route, array $params): string => 'https://app.example.com/f/' . $params['token']);

        return new InvoiceLinks($repository, $em, $url, $this->clock, $this->cipher);
    }

    private function invoice(): Invoice
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-10-10'));

        return new Invoice('2026012', 2026, 12, InvoiceType::FINAL, $reservation, new \DateTimeImmutable('2026-09-28'), null);
    }
}
