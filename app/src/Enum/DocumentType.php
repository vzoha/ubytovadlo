<?php

declare(strict_types=1);

namespace App\Enum;

enum DocumentType: string
{
    case PASSPORT = 'passport';
    case ID_CARD = 'id_card';
    case RESIDENCE_PERMIT = 'residence_permit';

    public function label(): string
    {
        return match ($this) {
            self::PASSPORT => 'Cestovní pas',
            self::ID_CARD => 'Občanský průkaz',
            self::RESIDENCE_PERMIT => 'Povolení k pobytu',
        };
    }
}
