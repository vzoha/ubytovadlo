<?php

declare(strict_types=1);

namespace App\Service\Electricity;

final class AllocationStats
{
    public function __construct(
        public int $intervals = 0,
        public int $reservations = 0,
        public int $skippedMeasured = 0,
    ) {
    }
}
