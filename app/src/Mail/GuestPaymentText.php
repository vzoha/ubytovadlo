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
use App\Entity\Reservation;
use App\Formatting\GuestDate;
use App\Invoice\IssuedInvoiceLink;

/**
 * Texty zpráv o platbě pro WhatsApp, SMS a chat — prostý text v jazyce hosta:
 * faktura s odkazem na PDF a připomínka doplatku (s odkazem na fakturu, je-li
 * vystavená). Nezaplacená částka vždy nese i platební údaje, ať host nemusí
 * nic otevírat, aby zaplatil.
 */
final class GuestPaymentText
{
    /** @var array<string, array<string, string>> */
    private const TEXTS = [
        'cs' => [
            'invoice' => <<<'TXT'
                Dobrý den,

                posíláme vám fakturu č. {{ invoice_number }} za pobyt {{ check_in }} – {{ check_out }}:
                {{ invoice_link }}

                Částka {{ invoice_total }} — {{ invoice_payment_status }}.
                TXT,
            'reminder' => <<<'TXT'
                Dobrý den,

                připomínáme doplatek za pobyt {{ check_in }} – {{ check_out }}: {{ balance_due }}{{ reminder_due }}.
                TXT,
            'invoice_payment' => <<<'TXT'
                Číslo účtu: {{ invoice_bank_account }}
                Variabilní symbol: {{ invoice_variable_symbol }}
                TXT,
            'reservation_payment' => <<<'TXT'
                Číslo účtu: {{ bank_account }}
                Variabilní symbol: {{ variable_symbol }}
                TXT,
            'reminder_link' => <<<'TXT'
                Fakturu č. {{ invoice_number }} najdete zde:
                {{ invoice_link }}
                TXT,
            'due' => ', splatnost %s',
            'link_expiry' => 'Odkaz platí do %s.',
            'outro' => <<<'TXT'
                Kdyby cokoli nesedělo, stačí napsat.

                S pozdravem
                {{ accommodation_name }}
                TXT,
        ],
        'en' => [
            'invoice' => <<<'TXT'
                Hello,

                here is invoice no. {{ invoice_number }} for your stay {{ check_in }} – {{ check_out }}:
                {{ invoice_link }}

                Amount {{ invoice_total }} — {{ invoice_payment_status }}.
                TXT,
            'reminder' => <<<'TXT'
                Hello,

                this is a reminder of the outstanding balance for your stay {{ check_in }} – {{ check_out }}: {{ balance_due }}{{ reminder_due }}.
                TXT,
            'invoice_payment' => <<<'TXT'
                Account number: {{ invoice_bank_account }}
                Payment reference: {{ invoice_variable_symbol }}
                TXT,
            'reservation_payment' => <<<'TXT'
                Account number: {{ bank_account }}
                Payment reference: {{ variable_symbol }}
                TXT,
            'reminder_link' => <<<'TXT'
                You can find invoice no. {{ invoice_number }} here:
                {{ invoice_link }}
                TXT,
            'due' => ', due by %s',
            'link_expiry' => 'The link is valid until %s.',
            'outro' => <<<'TXT'
                If anything looks wrong, just let us know.

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

    /** Faktura s odkazem na PDF; u nezaplacené i platební údaje. */
    public function invoice(Invoice $invoice, IssuedInvoiceLink $issued): string
    {
        $texts = $this->texts($invoice->getReservation());

        $blocks = [$texts['invoice']];
        if ($invoice->getPaidAt() === null) {
            $blocks[] = $texts['invoice_payment'];
        }
        $blocks[] = $this->linkExpiry($texts, $issued, $invoice->getReservation());

        return $this->render($invoice->getReservation(), $blocks, $texts, $invoice, $issued);
    }

    /**
     * Připomínka doplatku. S vystavenou fakturou nese její platební údaje
     * a odkaz na PDF, bez ní platební údaje rezervace.
     */
    public function reminder(Reservation $reservation, ?Invoice $invoice, ?IssuedInvoiceLink $issued): string
    {
        $texts = $this->texts($reservation);

        $blocks = [$texts['reminder'], $invoice !== null ? $texts['invoice_payment'] : $texts['reservation_payment']];
        if ($invoice !== null && $issued !== null) {
            $blocks[] = $texts['reminder_link'] . "\n" . $this->linkExpiry($texts, $issued, $reservation);
        }

        return $this->render($reservation, $blocks, $texts, $invoice, $issued);
    }

    /** @return array<string, string> */
    private function texts(Reservation $reservation): array
    {
        return self::TEXTS[$this->guestLocale->forReservation($reservation)] ?? self::TEXTS[MessageLocales::BASE];
    }

    /** @param array<string, string> $texts */
    private function linkExpiry(array $texts, IssuedInvoiceLink $issued, Reservation $reservation): string
    {
        return sprintf($texts['link_expiry'], GuestDate::format($issued->link->getExpiresAt(), $this->guestLocale->forReservation($reservation)));
    }

    /**
     * @param list<string>          $blocks
     * @param array<string, string> $texts
     */
    private function render(Reservation $reservation, array $blocks, array $texts, ?Invoice $invoice, ?IssuedInvoiceLink $issued): string
    {
        $blocks[] = $texts['outro'];
        $context = $invoice !== null ? $this->invoiceContext->forInvoice($invoice) : [];
        $text = $this->variables->renderBody(implode("\n\n", $blocks), $reservation, $context);

        // Odkaz a splatnost připomínky nejsou proměnné šablon — dosazují se až tady.
        $due = $context['invoice_due'] ?? '';

        return trim(strtr($text, [
            '{{ invoice_link }}' => $issued !== null ? $issued->url : '',
            '{{ reminder_due }}' => $due !== '' ? sprintf($texts['due'], $due) : '',
        ]));
    }
}
