<?php

declare(strict_types=1);

namespace App\Service\Electricity;

final readonly class ElectricityCost
{
    public function __construct(
        public float $vtCzk,
        public float $ntCzk,
        public float $totalCzk,
        public float $vtRate,
        public float $ntRate,
    ) {
    }
}
