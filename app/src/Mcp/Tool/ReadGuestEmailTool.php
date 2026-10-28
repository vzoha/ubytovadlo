<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\GuestEmailArgument;
use App\Mcp\GuestEmailPresenter;
use App\Mcp\McpTool;
use App\Mcp\McpToolEffect;
use App\Repository\GuestEmailRepository;

/** Celý text jednoho e-mailu a předchozí korespondence se stejným hostem. */
final class ReadGuestEmailTool implements McpTool
{
    private const CONVERSATION_LIMIT = 10;

    public function __construct(
        private readonly GuestEmailArgument $email,
        private readonly GuestEmailRepository $emails,
        private readonly GuestEmailPresenter $presenter,
    ) {
    }

    public function name(): string
    {
        return 'read_guest_email';
    }

    public function effect(): McpToolEffect
    {
        return McpToolEffect::READ;
    }

    public function description(): string
    {
        return 'Celý text e-mailu podle id z list_guest_emails nebo get_reservation: odesílatel, předmět, '
            . 'datum, text (včetně citace), názvy příloh, známý host a rezervace. Přidá přehled dalších '
            . 'e-mailů se stejnou adresou (conversation), ať je vidět, co už si host s majitelem napsali. '
            . 'Rezervace chybí, když nešla přiřadit jistě — pak ji dohledej přes find_guest.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'id e-mailu'],
            ],
            'required' => ['id'],
        ];
    }

    public function call(array $arguments): array
    {
        $email = $this->email->resolve($arguments);

        return $this->presenter->full($email) + [
            'conversation' => array_map($this->presenter->headline(...), $this->emails->findConversation($email, self::CONVERSATION_LIMIT)),
        ];
    }
}
