<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\GuestEmailPresenter;
use App\Mcp\McpTool;
use App\Repository\GuestEmailRepository;
use Psr\Clock\ClockInterface;

/** Co hosté poslali: doručené e-maily z korespondence s hosty, uložené v DB. */
final class ListGuestEmailsTool implements McpTool
{
    private const MAX_DAYS = 90;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly GuestEmailRepository $emails,
        private readonly GuestEmailPresenter $presenter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return 'list_guest_emails';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Přehled e-mailů, které poslali hosté, od nejnovějšího: odesílatel, předmět, datum, '
            . 'jestli už je zodpovězený (majitel odpověděl ve stejném vlákně nebo zprávu označil), '
            . 'známý host a rezervace, pokud ji šlo jistě přiřadit. Výchozí je jen nezodpovězené '
            . 'za posledních 14 dní. Schránka se stahuje každých pár minut. '
            . 'Text a předchozí korespondenci vrací read_guest_email podle id.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_DAYS, 'description' => 'Kolik dní zpět (výchozí 14)'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Nejvýš zpráv (výchozí 20)'],
                'includeAnswered' => ['type' => 'boolean', 'description' => 'Vrátit i zodpovězené (výchozí ne)'],
            ],
        ];
    }

    public function call(array $arguments): array
    {
        $days = max(1, min(self::MAX_DAYS, (int) ($arguments['days'] ?? 14)));
        $limit = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? 20)));
        $since = $this->clock->now()->modify(sprintf('-%d days', $days))->setTime(0, 0);

        $emails = $this->emails->findInbound($since, (bool) ($arguments['includeAnswered'] ?? false), $limit);

        return [
            'days' => $days,
            'count' => count($emails),
            'emails' => array_map($this->presenter->headline(...), $emails),
        ];
    }
}
