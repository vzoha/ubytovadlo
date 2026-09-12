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
use App\Enum\SendMode;
use App\Enum\TimingAnchor;

/**
 * Výchozí šablony e-mailů hostům — text (předmět + tělo v Markdownu s proměnnými),
 * výchozí režim odesílání i časování na ose rezervace. Slouží jako základ pro
 * čerstvou instanci: provozovatel je v UI přepíše a override se uloží do DB.
 * Plánované zprávy startují v režimu ruční (objeví se na ose, nic se samo
 * neodešle), ostatní vypnuté — provozovatel je zapne, až bude chtít.
 *
 * @see SendMode
 */
final class MessageTemplateDefaults
{
    /**
     * @var array<string, array{subject: string, body: string, mode: SendMode, anchor?: TimingAnchor, offsetDays?: int, sendAt?: ?string}>
     */
    private const DEFAULTS = [
        'reservation_request' => [
            'subject' => 'Rezervace {{ check_in }} – {{ check_out }} — záloha k úhradě · {{ accommodation_name }}',
            'mode' => SendMode::DRAFT,
            'anchor' => TimingAnchor::CREATED,
            'offsetDays' => 0,
            'sendAt' => null,
            'body' => <<<'MD'
                Dobrý den, {{ guest_first_name_vocative }},

                děkujeme za vaši rezervaci. Termín **{{ check_in }} – {{ check_out }}** ({{ nights_word }}) pro vás držíme do splatnosti zálohy; poté se uvolní dalším zájemcům.

                Celková cena pobytu je **{{ price_total }}**. Rezervaci potvrdíme po přijetí zálohy **{{ deposit_amount }}**, která se z ceny odečte:

                - **Číslo účtu:** {{ bank_account }}
                - **Variabilní symbol:** {{ variable_symbol }}
                - **Splatnost:** {{ deposit_due }}

                Platbu můžete naskenovat z QR kódu:

                {{ deposit_qr }}

                Jakmile záloha dorazí, pošleme vám potvrzení. Kdybyste cokoli potřebovali, stačí odepsat.
                MD,
        ],
        'reservation_confirmed' => [
            'subject' => 'Rezervace potvrzena — {{ accommodation_name }}, příjezd {{ check_in }}',
            'mode' => SendMode::OFF,
            'body' => <<<'MD'
                Dobrý den, {{ guest_first_name_vocative }},

                vaše rezervace je **potvrzená** — těšíme se na vás!

                - **Příjezd:** {{ check_in }} od {{ check_in_time }}
                - **Odjezd:** {{ check_out }} do {{ check_out_time }}
                - **Délka pobytu:** {{ nights_word }}
                - **Adresa:** {{ accommodation_address }}

                Tři dny před příjezdem vám pošleme pokyny k cestě a předání klíčů.

                Kdybyste cokoli potřebovali, stačí odepsat.
                MD,
        ],
        'pre_arrival' => [
            'subject' => 'Těšíme se na vás — {{ accommodation_name }}, příjezd {{ check_in }}',
            'mode' => SendMode::DRAFT,
            'anchor' => TimingAnchor::CHECK_IN,
            'offsetDays' => -3,
            'sendAt' => '09:00',
            'body' => <<<'MD'
                Dobrý den, {{ guest_first_name_vocative }},

                už se na vás těšíme! Připomínáme detaily vašeho pobytu:

                - **Příjezd:** {{ check_in }} od {{ check_in_time }}
                - **Odjezd:** {{ check_out }} do {{ check_out_time }}
                - **Délka pobytu:** {{ nights_word }}
                - **Počet hostů:** {{ guests_total }}
                - **Adresa:** {{ accommodation_address }}

                *Sem napište, jak se k vám dostat, kde zaparkovat a jak proběhne předání klíčů.*

                K úhradě zbývá **{{ balance_due }}**.

                Pokud jste ho ještě nevyplnili, prosíme o online check-in:

                [[button:Dokončit online check-in|{{ checkin_url }}]]

                Přejeme šťastnou cestu. Kdybyste cokoli potřebovali, stačí odepsat.
                MD,
        ],
        'pre_departure' => [
            'subject' => 'Zítra odjezd — {{ accommodation_name }}',
            'mode' => SendMode::DRAFT,
            'anchor' => TimingAnchor::CHECK_OUT,
            'offsetDays' => -1,
            'sendAt' => '17:00',
            'body' => <<<'MD'
                Dobrý den, {{ guest_first_name_vocative }},

                zítra se s vámi rozloučíme. Ať odjezd proběhne hladce, posíláme pár drobností:

                - **Odjezd:** {{ check_out }} do {{ check_out_time }}

                *Sem napište, kam nechat klíče a v jakém stavu ubytování předat.*

                Děkujeme, že jste u nás byli. Kdybyste cokoli potřebovali, stačí odepsat.
                MD,
        ],
        'post_stay' => [
            'subject' => 'Děkujeme za návštěvu — {{ accommodation_name }}',
            'mode' => SendMode::DRAFT,
            'anchor' => TimingAnchor::CHECK_OUT,
            'offsetDays' => 1,
            'sendAt' => '10:00',
            'body' => <<<'MD'
                Dobrý den, {{ guest_first_name_vocative }},

                děkujeme, že jste u nás strávili {{ nights_word }}. Doufáme, že se vám pobyt líbil.

                Budeme rádi za vaši zpětnou vazbu nebo recenzi — pomůže nám i dalším hostům.

                *Sem vložte odkaz na recenzi.*

                Budeme se těšit na vaši další návštěvu. Kdybyste cokoli potřebovali, stačí odepsat.
                MD,
        ],
        'balance_reminder' => [
            'subject' => 'Doplatek za pobyt — {{ accommodation_name }}',
            'mode' => SendMode::DRAFT,
            'anchor' => TimingAnchor::CHECK_IN,
            'offsetDays' => 0,
            'sendAt' => '12:00',
            'body' => <<<'MD'
                Dobrý den, {{ guest_first_name_vocative }},

                dovolujeme si připomenout doplatek za váš pobyt ve výši **{{ balance_due }}**.

                - **Číslo účtu:** {{ invoice_bank_account }}
                - **Variabilní symbol:** {{ invoice_variable_symbol }}
                - **Splatnost:** {{ invoice_due }}

                {{ invoice_qr }}

                Zaplatit můžete také v hotovosti při příjezdu ({{ check_in }}).

                Děkujeme. Kdybyste cokoli potřebovali, stačí odepsat.
                MD,
        ],
        'invoice' => [
            'subject' => 'Faktura č. {{ invoice_number }} — {{ accommodation_name }}',
            'mode' => SendMode::OFF,
            'body' => <<<'MD'
                Dobrý den, {{ guest_first_name_vocative }},

                v příloze posíláme fakturu č. {{ invoice_number }} za pobyt {{ check_in }} – {{ check_out }}.

                **{{ invoice_total }}** — {{ invoice_payment_status }}

                {{ invoice_qr }}

                Kdyby na faktuře něco nesedělo, stačí odepsat.
                MD,
        ],
        'custom' => [
            'subject' => 'Zpráva — {{ accommodation_name }}',
            'mode' => SendMode::OFF,
            'body' => <<<'MD'
                Dobrý den, {{ guest_first_name_vocative }},


                MD,
        ],
    ];

    /**
     * Překlady výchozích textů. Nesou jen předmět a tělo — režim odesílání
     * i časování se čtou ze základního jazyka.
     *
     * @var array<string, array<string, array{subject: string, body: string}>>
     */
    private const TRANSLATIONS = [
        'en' => [
            'reservation_request' => [
                'subject' => 'Booking {{ check_in }} – {{ check_out }} — deposit due · {{ accommodation_name }}',
                'body' => <<<'MD'
                    Dear {{ guest_first_name }},

                    thank you for your booking. We are holding **{{ check_in }} – {{ check_out }}** ({{ nights_word }}) until the deposit is due; after that the dates open up again.

                    The total price of the stay is **{{ price_total }}**. We will confirm the booking once we receive the deposit of **{{ deposit_amount }}**, which is deducted from the price:

                    - **Account number:** {{ bank_account }}
                    - **Payment reference:** {{ variable_symbol }}
                    - **Due date:** {{ deposit_due }}

                    You can scan the payment from a QR code:

                    {{ deposit_qr }}

                    We will send you a confirmation as soon as the deposit arrives. If you need anything, just reply.
                    MD,
            ],
            'reservation_confirmed' => [
                'subject' => 'Booking confirmed — {{ accommodation_name }}, arrival {{ check_in }}',
                'body' => <<<'MD'
                    Dear {{ guest_first_name }},

                    your booking is **confirmed** — we are looking forward to your stay!

                    - **Arrival:** {{ check_in }} from {{ check_in_time }}
                    - **Departure:** {{ check_out }} until {{ check_out_time }}
                    - **Length of stay:** {{ nights_word }}
                    - **Address:** {{ accommodation_address }}

                    Three days before arrival we will send you directions and details about the keys.

                    If you need anything, just reply.
                    MD,
            ],
            'pre_arrival' => [
                'subject' => 'See you soon — {{ accommodation_name }}, arrival {{ check_in }}',
                'body' => <<<'MD'
                    Dear {{ guest_first_name }},

                    we are looking forward to your visit! A reminder of your stay:

                    - **Arrival:** {{ check_in }} from {{ check_in_time }}
                    - **Departure:** {{ check_out }} until {{ check_out_time }}
                    - **Length of stay:** {{ nights_word }}
                    - **Guests:** {{ guests_total }}
                    - **Address:** {{ accommodation_address }}

                    *Write here how to reach you, where to park and how the keys are handed over.*

                    The outstanding balance is **{{ balance_due }}**.

                    If you have not completed the online check-in yet, please do:

                    [[button:Complete online check-in|{{ checkin_url }}]]

                    Have a safe trip. If you need anything, just reply.
                    MD,
            ],
            'pre_departure' => [
                'subject' => 'Departure tomorrow — {{ accommodation_name }}',
                'body' => <<<'MD'
                    Dear {{ guest_first_name }},

                    we will be saying goodbye tomorrow. A few notes so your departure goes smoothly:

                    - **Departure:** {{ check_out }} until {{ check_out_time }}

                    *Write here where to leave the keys and in what state to hand the place over.*

                    Thank you for staying with us. If you need anything, just reply.
                    MD,
            ],
            'post_stay' => [
                'subject' => 'Thank you for your visit — {{ accommodation_name }}',
                'body' => <<<'MD'
                    Dear {{ guest_first_name }},

                    thank you for spending {{ nights_word }} with us. We hope you enjoyed your stay.

                    We would appreciate your feedback or a review — it helps us and future guests alike.

                    *Put a link to your review page here.*

                    We hope to welcome you again. If you need anything, just reply.
                    MD,
            ],
            'balance_reminder' => [
                'subject' => 'Balance due for your stay — {{ accommodation_name }}',
                'body' => <<<'MD'
                    Dear {{ guest_first_name }},

                    this is a reminder of the outstanding balance for your stay: **{{ balance_due }}**.

                    - **Account number:** {{ invoice_bank_account }}
                    - **Payment reference:** {{ invoice_variable_symbol }}
                    - **Due date:** {{ invoice_due }}

                    {{ invoice_qr }}

                    You can also pay in cash on arrival ({{ check_in }}).

                    Thank you. If you need anything, just reply.
                    MD,
            ],
            'invoice' => [
                'subject' => 'Invoice no. {{ invoice_number }} — {{ accommodation_name }}',
                'body' => <<<'MD'
                    Dear {{ guest_first_name }},

                    please find attached invoice no. {{ invoice_number }} for your stay {{ check_in }} – {{ check_out }}.

                    **{{ invoice_total }}** — {{ invoice_payment_status }}

                    {{ invoice_qr }}

                    If anything on the invoice looks wrong, just reply.
                    MD,
            ],
            'custom' => [
                'subject' => 'Message — {{ accommodation_name }}',
                'body' => <<<'MD'
                    Dear {{ guest_first_name }},


                    MD,
            ],
        ],
    ];

    public static function for(MessageKind $kind): MessageTemplate
    {
        $default = self::DEFAULTS[$kind->value]
            ?? throw new \LogicException(sprintf('Chybí výchozí šablona pro druh zprávy „%s".', $kind->value));

        $template = new MessageTemplate($kind, $default['subject'], $default['body']);
        $template->setMode($default['mode']);
        if (isset($default['anchor'])) {
            $template->setTiming($default['anchor'], $default['offsetDays'], $default['sendAt']);
        }

        return $template;
    }

    /** Výchozí překlad, nebo null, když pro daný jazyk žádný není. */
    public static function forLocale(MessageKind $kind, string $locale): ?MessageTemplate
    {
        $default = self::TRANSLATIONS[$locale][$kind->value] ?? null;
        if ($default === null) {
            return null;
        }

        return new MessageTemplate($kind, $default['subject'], $default['body'], $locale);
    }
}
