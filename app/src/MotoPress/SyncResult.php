<?php

declare(strict_types=1);

namespace App\MotoPress;

final class SyncResult
{
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly int $total,
        public readonly int $skipped = 0,
    ) {
    }
}
