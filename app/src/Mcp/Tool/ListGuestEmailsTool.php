<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Email\GuestInbox\GuestInbox;
use App\Email\GuestInbox\GuestInboxException;
use App\Email\GuestInbox\InboxSummary;
use App\Mcp\McpTool;
use App\Mcp\McpToolException;
use App\Repository\CustomerRepository;

/**
 * Co hosté poslali: přehled hlaviček zpráv ze schránky hostů a jestli odesílatele
 * známe. Těla se nestahují (přílohy by přehled zpomalily), text vrací read_guest_email.
 */
final class ListGuestEmailsTool implements McpTool
{
    private const MAX_DAYS = 90;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly GuestInbox $inbox,
        private readonly CustomerRepository $customers,
    ) {
    }

    public function name(): string
    {
        return 'list_guest_emails';
    }

    public function description(): string
    {
        return 'Přehled e-mailů ve schránce, kam píšou hosté, od nejnovějšího: odesílatel, předmět, datum, '
            . 'jestli už byla zodpovězena (příznak Answered z poštovního klienta) '
            . 'a jestli odesílatele známe jako hosta. Výchozí je jen nezodpovězené za posledních 14 dní. '
            . 'Text zprávy načti přes read_guest_email podle uid.';
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
        $includeAnswered = (bool) ($arguments['includeAnswered'] ?? false);

        try {
            $messages = $this->inbox->recent($days, $limit);
        } catch (GuestInboxException $e) {
            throw new McpToolException($e->getMessage(), previous: $e);
        }

        if (!$includeAnswered) {
            $messages = array_values(array_filter($messages, static fn (InboxSummary $m): bool => !$m->answered));
        }

        return [
            'days' => $days,
            'count' => count($messages),
            'emails' => array_map($this->row(...), $messages),
        ];
    }

    /** @return array<string, mixed> */
    private function row(InboxSummary $message): array
    {
        $guest = $message->from->email !== null ? ($this->customers->findByEmail($message->from->email)[0] ?? null) : null;

        return [
            'uid' => $message->uid,
            'from' => $message->from->toArray(),
            'knownGuest' => $guest?->getDisplayName(),
            'subject' => $message->subject,
            'date' => $message->date->format('Y-m-d H:i'),
            'answered' => $message->answered,
        ];
    }
}
