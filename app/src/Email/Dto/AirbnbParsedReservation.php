<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\Dto;

final class AirbnbParsedReservation
{
    public function __construct(
        public readonly string $confirmationCode,
        public readonly string $guestName,
        public readonly ?string $guestRegion,
        public readonly \DateTimeImmutable $checkIn,
        public readonly \DateTimeImmutable $checkOut,
        public readonly ?\DateTimeImmutable $checkInTime,
        public readonly ?\DateTimeImmutable $checkOutTime,
        public readonly int $guestsAdult,
        public readonly int $guestsChild,
        public readonly int $guestsInfant,
        public readonly ?float $pricePerNight,
        public readonly ?int $nights,
        public readonly ?float $priceTotal,
        public readonly ?float $hostCommission,
        public readonly ?float $netPayout,
        public readonly bool $hasPet = false,
        public readonly ?string $petsNote = null,
    ) {
    }
}
