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

/**
 * Výchozí texty šablon e-mailů (předmět + tělo v Markdownu s proměnnými). Slouží
 * jako základ pro čerstvou instanci — provozovatel je v UI přepíše a override se
 * uloží do DB. Záměrně neutrální, bez konkrétní instance.
 */
final class MessageTemplateDefaults
{
    /** @var array<string, array{subject: string, body: string}> */
    private const DEFAULTS = [
        'pre_arrival' => [
            'subject' => 'Těšíme se na vás — {{ accommodation_name }}, příjezd {{ check_in }}',
            'body' => <<<'MD'
                Dobrý den {{ guest_first_name }},

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
            'body' => <<<'MD'
                Dobrý den {{ guest_first_name }},

                děkujeme, že jste u nás strávili {{ nights }} nocí. Doufáme, že se vám pobyt líbil.

                Budeme rádi za vaši zpětnou vazbu nebo recenzi — pomůže nám i dalším hostům.

                Budeme se těšit na vaši další návštěvu!
                MD,
        ],
        'balance_reminder' => [
            'subject' => 'Doplatek za pobyt — {{ accommodation_name }}',
            'body' => <<<'MD'
                Dobrý den {{ guest_first_name }},

                dovolujeme si připomenout doplatek za váš pobyt ve výši **{{ balance_due }}**.

                Doplatek lze uhradit převodem dle faktury, nebo v hotovosti při příjezdu ({{ check_in }}).

                Děkujeme!
                MD,
        ],
        'invoice' => [
            'subject' => 'Faktura č. {{ invoice_number }} — {{ accommodation_name }}',
            'body' => <<<'MD'
                Dobrý den {{ guest_first_name }},

                v příloze zasíláme fakturu č. {{ invoice_number }} za pobyt ({{ check_in }} — {{ check_out }}).

                Děkujeme a přejeme příjemný den.
                MD,
        ],
        'custom' => [
            'subject' => 'Zpráva — {{ accommodation_name }}',
            'body' => <<<'MD'
                Dobrý den {{ guest_first_name }},


                MD,
        ],
    ];

    public static function for(MessageKind $kind): MessageTemplate
    {
        $default = self::DEFAULTS[$kind->value];

        return new MessageTemplate($kind, $default['subject'], $default['body']);
    }
}
