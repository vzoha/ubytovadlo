<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller\Concern;

use Symfony\Component\HttpFoundation\Request;

/**
 * Ověření CSRF tokenu z POST formuláře pro controllery. Neplatný token skončí
 * jako 403 — jedno místo pro celou akční vrstvu, ať se kontrola nepíše u každé
 * mutující akce zvlášť.
 *
 * Pro použití v controlleru dědícím z `AbstractController` (poskytuje
 * `isCsrfTokenValid()` i `createAccessDeniedException()`).
 */
trait ChecksCsrf
{
    protected function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
