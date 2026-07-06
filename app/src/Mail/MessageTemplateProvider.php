<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Entity\MessageTemplate;
use App\Enum\MessageKind;
use App\Repository\MessageTemplateRepository;

/**
 * Efektivní šablona pro daný druh zprávy: override z DB, jinak výchozí z kódu.
 * Díky tomu má čerstvá instance funkční texty bez seedování.
 */
class MessageTemplateProvider
{
    public function __construct(
        private readonly MessageTemplateRepository $templates,
    ) {
    }

    public function for(MessageKind $kind): MessageTemplate
    {
        return $this->templates->findByKind($kind) ?? MessageTemplateDefaults::for($kind);
    }
}
