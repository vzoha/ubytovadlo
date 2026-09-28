<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Entity\Invoice;
use App\Formatting\GuestDate;
use App\Invoice\IssuedInvoiceLink;

/**
 * Text zprávy s odkazem na fakturu pro WhatsApp, SMS a chat — prostý text
 * v jazyce hosta. U nezaplacené faktury nese i platební údaje, ať host
 * nemusí PDF otevírat, aby zaplatil.
 */
final class InvoiceLinkMessage
{
    /** @var array<string, array{intro: string, payment: string, outro: string}> */
    private const TEXTS = [
        'cs' => [
            'intro' => <<<'TXT'
                Dobrý den,

                posíláme vám fakturu č. {{ invoice_number }} za pobyt {{ check_in }} – {{ check_out }}:
                {{ invoice_link }}

                Částka {{ invoice_total }} — {{ invoice_payment_status }}.
                TXT,
            'payment' => <<<'TXT'
                Číslo účtu: {{ invoice_bank_account }}
                Variabilní symbol: {{ invoice_variable_symbol }}
                TXT,
            'outro' => <<<'TXT'
                Odkaz platí do {{ invoice_link_expires }}. Kdyby na faktuře něco nesedělo, stačí napsat.

                S pozdravem
                {{ accommodation_name }}
                TXT,
        ],
        'en' => [
            'intro' => <<<'TXT'
                Hello,

                here is invoice no. {{ invoice_number }} for your stay {{ check_in }} – {{ check_out }}:
                {{ invoice_link }}

                Amount {{ invoice_total }} — {{ invoice_payment_status }}.
                TXT,
            'payment' => <<<'TXT'
                Account number: {{ invoice_bank_account }}
                Payment reference: {{ invoice_variable_symbol }}
                TXT,
            'outro' => <<<'TXT'
                The link is valid until {{ invoice_link_expires }}. If anything on the invoice looks wrong, just let us know.

                Best regards
                {{ accommodation_name }}
                TXT,
        ],
    ];

    public function __construct(
        private readonly MessageVariableResolver $variables,
        private readonly InvoiceMessageContext $invoiceContext,
        private readonly GuestLocaleResolver $guestLocale,
    ) {
    }

    public function render(Invoice $invoice, IssuedInvoiceLink $issued): string
    {
        $reservation = $invoice->getReservation();
        $locale = $this->guestLocale->forReservation($reservation);
        $texts = self::TEXTS[$locale] ?? self::TEXTS[MessageLocales::BASE];

        $parts = [$texts['intro']];
        if ($invoice->getPaidAt() === null) {
            $parts[] = $texts['payment'];
        }
        $parts[] = $texts['outro'];

        $text = $this->variables->renderBody(implode("\n\n", $parts), $reservation, $this->invoiceContext->forInvoice($invoice));

        // Odkaz existuje jen při odeslání, mezi proměnnými šablon ho proto nenabízíme
        // a dosazujeme ho až tady.
        return trim(strtr($text, [
            '{{ invoice_link }}' => $issued->url,
            '{{ invoice_link_expires }}' => GuestDate::format($issued->link->getExpiresAt(), $locale),
        ]));
    }
}
