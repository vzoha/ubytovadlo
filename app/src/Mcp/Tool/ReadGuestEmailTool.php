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
use App\Mcp\McpTool;
use App\Mcp\McpToolException;

/** Celý text jedné zprávy ze schránky hostů. */
final class ReadGuestEmailTool implements McpTool
{
    /** Strop délky textu — dlouhé citace starší korespondence model k odpovědi nepotřebuje celé. */
    private const MAX_TEXT = 20000;

    public function __construct(
        private readonly GuestInbox $inbox,
    ) {
    }

    public function name(): string
    {
        return 'read_guest_email';
    }

    public function description(): string
    {
        return 'Celý text e-mailu ze schránky hostů podle uid z list_guest_emails: odesílatel, předmět, datum, '
            . 'text (včetně citované předchozí korespondence) a názvy příloh. Zprávu nechá nepřečtenou. '
            . 'Pak dohledej hosta přes find_guest podle adresy odesílatele.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'uid' => ['type' => 'string', 'description' => 'uid zprávy z list_guest_emails'],
            ],
            'required' => ['uid'],
        ];
    }

    public function call(array $arguments): array
    {
        $uid = trim((string) ($arguments['uid'] ?? ''));
        if ($uid === '') {
            throw new McpToolException('Zadej uid zprávy.');
        }

        try {
            $message = $this->inbox->find($uid);
        } catch (GuestInboxException $e) {
            throw new McpToolException($e->getMessage(), previous: $e);
        }
        if ($message === null) {
            throw new McpToolException(sprintf('Zpráva s uid %s ve schránce není.', $uid));
        }

        $summary = $message->summary;

        return [
            'uid' => $summary->uid,
            'messageId' => $message->messageId,
            'inReplyTo' => $message->inReplyTo,
            'from' => $summary->from->toArray(),
            'subject' => $summary->subject,
            'date' => $summary->date->format('Y-m-d H:i'),
            'answered' => $summary->answered,
            'text' => mb_strimwidth($message->text, 0, self::MAX_TEXT, "\n[…zkráceno]"),
            'attachments' => $message->attachmentNames,
        ];
    }
}
