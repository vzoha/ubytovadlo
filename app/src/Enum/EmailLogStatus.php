<?php

declare(strict_types=1);

namespace App\Enum;

enum EmailLogStatus: string
{
    case PENDING = 'pending';
    case PROCESSED = 'processed';
    case IGNORED = 'ignored';
    case ERROR = 'error';
}
