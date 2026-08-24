<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Task;

use App\Entity\Account;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Vstup pro zápis provedení úkonu. `bookTo` s vyplněnou cenou znamená
 * „zaúčtovat jako výdaj na tento účet"; bez něj se cena jen eviduje v historii.
 */
final readonly class CompletionInput
{
    public function __construct(
        public \DateTimeImmutable $doneOn,
        public ?\DateTimeImmutable $validUntil = null,
        public ?string $vendor = null,
        public ?int $costCzk = null,
        public ?string $note = null,
        public ?UploadedFile $attachment = null,
        public ?Account $bookTo = null,
    ) {
    }
}
