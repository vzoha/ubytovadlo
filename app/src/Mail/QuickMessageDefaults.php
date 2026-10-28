<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Entity\QuickMessage;
use App\Enum\MessageKind;

/**
 * Připravené texty rychlých zpráv pokrývající běžný pobyt od potvrzení
 * po poděkování. Slouží dvěma způsoby: nastavená instance jimi dostane seznam
 * naplněný ({@see QuickMessageSeeder}) a formulář nové zprávy je nabízí jako
 * vzor k předvyplnění. Zpráva v databázi je od té chvíle samostatná — vzor
 * se do ní nijak nepromítá.
 *
 * Texty jsou prostý text bez formátování — míří do SMS, WhatsAppu a chatu portálů.
 * Vzor s druhem zastupuje automatickou zprávu stejného obsahu.
 *
 * @see MessageVariableResolver::plainTextVariables() dostupné proměnné
 */
final class QuickMessageDefaults
{
    /** @var list<array{label: string, body: string, kind?: MessageKind}> */
    private const DEFAULTS = [
        [
            'label' => 'Uvítání',
            'body' => <<<'TXT'
                Dobrý den,

                moc děkujeme za vaši rezervaci. Termín {{ check_in }} – {{ check_out }} ({{ nights_word }}) pro vás držíme.

                Pár dní před příjezdem se ozveme s podrobnostmi k cestě a předání klíčů.

                Budeme se na vás těšit.
                TXT,
        ],
        [
            'label' => 'Online check-in',
            'body' => <<<'TXT'
                Dobrý den,

                váš pobyt {{ check_in }} – {{ check_out }} se blíží, a tak vás poprosíme o vyplnění online check-inu. Zabere pár minut a při příjezdu se pak nebudeme zdržovat papírováním:

                {{ checkin_url }}

                Ptáme se v něm na fakturační údaje a na údaje ubytovaných — evidenci hostů nám ukládá zákon.

                Děkujeme a budeme se na vás těšit.
                TXT,
        ],
        [
            'label' => 'Pokyny k příjezdu',
            'kind' => MessageKind::PRE_ARRIVAL,
            'body' => <<<'TXT'
                Dobrý den,

                váš pobyt se blíží, a tak vám posíláme podrobnosti k příjezdu.

                Adresa: {{ accommodation_address }}
                Příjezd {{ check_in }} od {{ check_in_time }}, odjezd {{ check_out }} do {{ check_out_time }}.

                [Sem napište, jak se k nám dostat a kde zaparkovat.]

                Po příjezdu se prosím ozvěte, domluvíme se na předání klíčů.

                K úhradě zbývá {{ balance_due }}.

                Pokud jste ještě nevyplnili online check-in, moc nám pomůže, když to stihnete před příjezdem: {{ checkin_url }}

                V kolik hodin vás máme očekávat?
                TXT,
        ],
        [
            'label' => 'Před odjezdem',
            'kind' => MessageKind::PRE_DEPARTURE,
            'body' => <<<'TXT'
                Dobrý den,

                pobyt se vám pomalu chýlí ke konci — odjezd máte {{ check_out }} do {{ check_out_time }}.

                [Sem napište, kam nechat klíče a v jakém stavu ubytování předat.]

                Dejte prosím vědět, kolem kolika hodin plánujete vyrazit.
                TXT,
        ],
        [
            'label' => 'Poděkování po pobytu',
            'kind' => MessageKind::POST_STAY,
            'body' => <<<'TXT'
                Dobrý den,

                moc děkujeme za návštěvu — doufáme, že jste si pobyt užili a odjížděli spokojení.

                Kdyby vám cokoli chybělo nebo vás něco napadlo, budeme rádi za zpětnou vazbu. A pokud se vám u nás líbilo, potěší nás i hodnocení.

                [Sem vložte odkaz na hodnocení.]

                Budeme se těšit na shledanou.
                TXT,
        ],
    ];

    /**
     * Vzory pro předvyplnění formuláře.
     *
     * @return list<array{label: string, body: string, kind: string|null}>
     */
    public static function templates(): array
    {
        return array_map(static fn (array $default): array => [
            'label' => $default['label'],
            'body' => $default['body'],
            'kind' => ($default['kind'] ?? null)?->value,
        ], self::DEFAULTS);
    }

    /** @return list<QuickMessage> */
    public static function create(): array
    {
        $messages = [];
        foreach (self::DEFAULTS as $order => $default) {
            $message = new QuickMessage($default['label'], $default['body']);
            $message->setSortOrder($order);
            $message->setKind($default['kind'] ?? null);
            $messages[] = $message;
        }

        return $messages;
    }
}
