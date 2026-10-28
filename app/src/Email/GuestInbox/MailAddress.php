<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\GuestInbox;

/** Adresa v hlavičce zprávy (odesílatel, příjemce); e-mail malými písmeny kvůli párování s hostem. */
final readonly class MailAddress
{
    public function __construct(
        public ?string $name,
        public ?string $email,
    ) {
    }

    /** @return array{name: ?string, email: ?string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }
}
