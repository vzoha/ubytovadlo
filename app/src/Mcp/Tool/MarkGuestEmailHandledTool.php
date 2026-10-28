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
use App\Mcp\McpTool;
use App\Mcp\McpToolEffect;
use App\Mcp\McpToolException;
use Doctrine\ORM\EntityManagerInterface;

/** Označí e-mail jako vyřízený bez odpovědi — stejně jako tlačítko Vyřízeno v aplikaci. */
final class MarkGuestEmailHandledTool implements McpTool
{
    public function __construct(
        private readonly GuestEmailArgument $email,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'mark_guest_email_handled';
    }

    public function effect(): McpToolEffect
    {
        return McpToolEffect::CHANGE;
    }

    public function description(): string
    {
        return 'Označí e-mail od hosta (id z list_guest_emails) jako vyřízený, aniž by se odpovídalo — '
            . 'typicky reklama, newsletter nebo dotaz vyřízený telefonem. Z přehledu nezodpovězených '
            . 'pak zmizí. Volej jen na pokyn majitele.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'id e-mailu od hosta'],
            ],
            'required' => ['id'],
        ];
    }

    public function call(array $arguments): array
    {
        $email = $this->email->resolve($arguments);
        if (!$email->isInbound()) {
            throw new McpToolException('Vyřídit jde jen e-mail od hosta, ne odeslanou odpověď.');
        }

        $email->markAnswered();
        $this->em->flush();

        return ['id' => $email->getId(), 'answered' => true];
    }
}
