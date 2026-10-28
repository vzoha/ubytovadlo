<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\GuestMail\GuestEmailReplier;
use App\Mcp\McpTool;
use App\Mcp\McpToolException;
use App\Repository\GuestEmailRepository;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Odešle majitelem schválenou odpověď na e-mail hosta — stejnou cestou jako
 * tlačítko Odpovědět v aplikaci (vlákno, korespondence, označení zodpovězeno).
 */
final class ReplyToGuestEmailTool implements McpTool
{
    public function __construct(
        private readonly GuestEmailRepository $emails,
        private readonly GuestEmailReplier $replier,
    ) {
    }

    public function name(): string
    {
        return 'reply_to_guest_email';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function description(): string
    {
        return 'Odešle hostovi odpověď na jeho e-mail (id z list_guest_emails) ze schránky ubytování, '
            . 'do stejného vlákna. Odpověď se uloží do korespondence a e-mail se označí jako zodpovězený. '
            . 'Volej jen s přesným textem, který majitel v konverzaci výslovně schválil k odeslání — '
            . 'nikdy s konceptem, který ještě neviděl, ani s textem obsahujícím místa k doplnění [Sem …]. '
            . 'Text je prostý (bez Markdownu), včetně podpisu; citaci původní zprávy nepřidávej. '
            . 'Odeslání nejde vzít zpět a každé volání pošle e-mail znovu.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'id e-mailu od hosta, na který se odpovídá'],
                'text' => ['type' => 'string', 'description' => 'Schválený text odpovědi včetně podpisu'],
                'subject' => ['type' => 'string', 'description' => 'Předmět; bez něj „Re: " + původní předmět'],
            ],
            'required' => ['id', 'text'],
        ];
    }

    public function call(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        $email = $id > 0 ? $this->emails->find($id) : null;
        if ($email === null) {
            throw new McpToolException(sprintf('E-mail s id %d neexistuje.', $id));
        }
        $text = is_string($arguments['text'] ?? null) ? $arguments['text'] : '';
        if (str_contains($text, '[Sem ')) {
            throw new McpToolException('Text obsahuje místo k doplnění [Sem …] — doplň ho s majitelem, pak odešli.');
        }

        try {
            $reply = $this->replier->reply($email, is_string($arguments['subject'] ?? null) ? $arguments['subject'] : '', $text);
        } catch (\InvalidArgumentException $e) {
            throw new McpToolException($e->getMessage(), previous: $e);
        } catch (TransportExceptionInterface $e) {
            throw new McpToolException('Odpověď se nepodařilo odeslat: ' . $e->getMessage(), previous: $e);
        }

        return [
            'sent' => true,
            'id' => $reply->getId(),
            'to' => $reply->getCounterpartEmail(),
            'subject' => $reply->getSubject(),
        ];
    }
}
