<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Entity\Invoice;
use App\Entity\InvoiceLink;
use App\Repository\InvoiceLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Veřejné odkazy na PDF faktury. Token (256 bitů) je jen v URL, v databázi
 * leží jeho sha256 otisk. Odkaz platí LIFETIME_DAYS dní a jde ho zrušit.
 */
final class InvoiceLinks
{
    public const LIFETIME_DAYS = 60;

    /** Token v URL: base64url z 32 bajtů = 43 znaků. */
    public const TOKEN_PATTERN = '[A-Za-z0-9_-]{43}';

    public function __construct(
        private readonly InvoiceLinkRepository $links,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function issue(Invoice $invoice): IssuedInvoiceLink
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now();
        $link = new InvoiceLink($invoice, self::hash($token), $now, $now->modify('+' . self::LIFETIME_DAYS . ' days'));
        $this->em->persist($link);
        $this->em->flush();

        return new IssuedInvoiceLink(
            $link,
            $this->urlGenerator->generate('invoice_link_open', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    /** Platný odkaz k tokenu (a zapíše otevření), jinak null — neznámý, vypršelý i zrušený vypadá stejně. */
    public function open(string $token): ?InvoiceLink
    {
        $link = $this->links->findOneByTokenHash(self::hash($token));
        $now = $this->clock->now();
        if ($link === null || !$link->isActive($now)) {
            return null;
        }

        $link->markOpened($now);
        $this->em->flush();

        return $link;
    }

    public function isActive(InvoiceLink $link): bool
    {
        return $link->isActive($this->clock->now());
    }

    public function revoke(InvoiceLink $link): void
    {
        $link->revoke($this->clock->now());
        $this->em->flush();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
