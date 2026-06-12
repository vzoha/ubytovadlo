<?php

declare(strict_types=1);

namespace App\Email;

final class EmailAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $contentType,
        public readonly string $content,
    ) {
    }
}
