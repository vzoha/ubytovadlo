<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\GuestEmail;
use App\Repository\GuestEmailRepository;

/** E-mail hosta podle argumentu `id` nástroje. */
final class GuestEmailArgument
{
    public function __construct(private readonly GuestEmailRepository $emails)
    {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws McpToolException když e-mail neexistuje
     */
    public function resolve(array $arguments): GuestEmail
    {
        $id = (int) ($arguments['id'] ?? 0);
        $email = $id > 0 ? $this->emails->find($id) : null;
        if ($email === null) {
            throw new McpToolException(sprintf('E-mail s id %d neexistuje.', $id));
        }

        return $email;
    }
}
