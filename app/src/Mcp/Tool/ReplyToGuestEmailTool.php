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
use App\Mcp\GuestEmailArgument;
use App\Mcp\McpTool;
use App\Mcp\McpToolEffect;
use App\Mcp\McpToolException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Odešle majitelem schválenou odpověď na e-mail hosta — stejnou cestou jako
 * tlačítko Odpovědět v aplikaci (vlákno, korespondence, označení zodpovězeno).
 */
final class ReplyToGuestEmailTool implements McpTool
{
    /** Začátek místa k doplnění, které asistent nechává v konceptu (viz PropertyInfoTool). */
    private const UNFILLED_PLACEHOLDER = '[Sem ';

    public function __construct(
        private readonly GuestEmailArgument $email,
        private readonly GuestEmailReplier $replier,
    ) {
    }

    public function name(): string
    {
        return 'reply_to_guest_email';
    }

    public function effect(): McpToolEffect
    {
        return McpToolEffect::SEND;
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
        $email = $this->email->resolve($arguments);
        $text = (string) ($arguments['text'] ?? '');
        $subject = (string) ($arguments['subject'] ?? '');
        if (str_contains($text, self::UNFILLED_PLACEHOLDER)) {
            throw new McpToolException('Text obsahuje místo k doplnění [Sem …] — doplň ho s majitelem, pak odešli.');
        }

        try {
            $reply = $this->replier->reply($email, $subject, $text);
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
