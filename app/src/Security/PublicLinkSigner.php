<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Podpis veřejné adresy jednoho zdroje (např. QR kódu faktury). Podpis
 * opravňuje jen k tomu zdroji, pod kterým vznikl — přeposlaný e-mail tak
 * neotevře nic dalšího z rezervace (na rozdíl od check-in tokenu).
 */
final class PublicLinkSigner
{
    private readonly string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $secret)
    {
        // Vlastní odvozený klíč, ať podpis nesdílí tajemství s ostatními
        // uživateli kernel.secret (remember-me, CSRF).
        $this->key = hash_hmac('sha256', 'public-link', $secret);
    }

    public function sign(string $resource, int $id): string
    {
        return hash_hmac('sha256', $resource . ':' . $id, $this->key);
    }

    public function verify(string $resource, int $id, string $signature): bool
    {
        return hash_equals($this->sign($resource, $id), $signature);
    }
}
