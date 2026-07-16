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
            'subject' => 'Rezervace {{ variable_symbol }} — zbývá zaplatit zálohu · {{ accommodation_name }}',
            'mode' => SendMode::DRAFT,
            'anchor' => TimingAnchor::CREATED,
            'offsetDays' => 0,
            'sendAt' => null,
            'body' => <<<'MD'
                Dobrý den, {{ guest_first_name_vocative }},

                děkujeme za vaši rezervaci. Termín pobytu **{{ check_in }} — {{ check_out }}** pro vás držíme.

                Rezervaci potvrdíme po přijetí zálohy **{{ deposit_amount }}** (odečte se z celkové ceny):

                - **Číslo účtu:** {{ bank_account }}
                - **Variabilní symbol:** {{ variable_symbol }}
                - **Splatnost:** {{ deposit_due }}

                Platbu můžete pohodlně naskenovat z QR kódu:

                {{ deposit_qr }}

                Jakmile záloha dorazí, pošleme vám potvrzení. Děkujeme!
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
                - **Počet nocí:** {{ nights }}

                Pár dní před příjezdem vám pošleme podrobné pokyny k cestě a předání klíčů.

                V případě dotazů jsme vám k dispozici.
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
                - **Počet nocí:** {{ nights }}
                - **Počet hostů:** {{ guests_total }}

                Pokud jste tak ještě neučinili, vyplňte prosím online check-in:

                [[button:Dokončit online check-in|{{ checkin_url }}]]

                V případě dotazů jsme vám k dispozici. Přejeme šťastnou cestu!
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

                děkujeme, že jste u nás strávili {{ nights }} nocí. Doufáme, že se vám pobyt líbil.

                Budeme rádi za vaši zpětnou vazbu nebo recenzi — pomůže nám i dalším hostům.

                Budeme se těšit na vaši další návštěvu!
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

                Doplatek lze uhradit převodem dle faktury, nebo v hotovosti při příjezdu ({{ check_in }}).

                Děkujeme!
                MD,
        ],
        'invoice' => [
            'subject' => 'Faktura č. {{ invoice_number }} — {{ accommodation_name }}',
            'mode' => SendMode::OFF,
            'body' => <<<'MD'
                Dobrý den, {{ guest_first_name_vocative }},

                v příloze zasíláme fakturu č. {{ invoice_number }} za pobyt ({{ check_in }} — {{ check_out }}).

                Děkujeme a přejeme příjemný den.
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
}
