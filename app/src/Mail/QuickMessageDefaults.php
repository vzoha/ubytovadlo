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

/**
 * Připravené texty rychlých zpráv pokrývající běžný pobyt od potvrzení
 * po poděkování. Slouží dvěma způsoby: nastavená instance jimi dostane seznam
 * naplněný ({@see QuickMessageSeeder}) a formulář nové zprávy je nabízí jako
 * vzor k předvyplnění. Zpráva v databázi je od té chvíle samostatná — vzor
 * se do ní nijak nepromítá.
 *
 * Texty jsou prostý text bez formátování — míří do SMS, WhatsAppu a chatu portálů.
 *
 * @see MessageVariableResolver::plainTextVariables() dostupné proměnné
 */
final class QuickMessageDefaults
{
    /** @var list<array{label: string, body: string}> */
    private const DEFAULTS = [
        [
            'label' => 'Uvítání',
            'body' => <<<'TXT'
                Dobrý den,

                moc děkujeme za Vaši rezervaci. Termín {{ check_in }} — {{ check_out }} pro Vás držíme.

                Pár dní před příjezdem se ozveme s podrobnostmi k cestě a předání klíčů. Kdybyste cokoli potřebovali dřív, stačí napsat.

                Budeme se na Vás těšit.
                TXT,
        ],
        [
            'label' => 'Online check-in',
            'body' => <<<'TXT'
                Dobrý den,

                Váš pobyt {{ check_in }} — {{ check_out }} se blíží, a tak Vás poprosíme o vyplnění online check-inu. Zabere pár minut a při příjezdu se pak nebudeme zdržovat papírováním:

                {{ checkin_url }}

                Ptáme se v něm na fakturační údaje a na údaje ubytovaných — evidenci hostů nám ukládá zákon.

                Budeme se na Vás těšit.
                TXT,
        ],
        [
            'label' => 'Pokyny k příjezdu',
            'body' => <<<'TXT'
                Dobrý den,

                Váš pobyt se blíží, a tak Vám posíláme podrobnosti k příjezdu.

                Adresa: {{ accommodation_address }}
                Příjezd {{ check_in }} od {{ check_in_time }}, odjezd {{ check_out }} do {{ check_out_time }}.

                Po příjezdu se prosím ozvěte, domluvíme se na předání klíčů.

                Pokud jste ještě nevyplnili online check-in, moc nám pomůže, když to stihnete před příjezdem: {{ checkin_url }}

                V kolik hodin Vás máme očekávat?
                TXT,
        ],
        [
            'label' => 'Poděkování po pobytu',
            'body' => <<<'TXT'
                Dobrý den,

                moc děkujeme za návštěvu — doufáme, že jste si pobyt užili a odjížděli spokojení.

                Kdyby Vám cokoli chybělo nebo Vás něco napadlo, budeme rádi za zpětnou vazbu. A pokud se Vám u nás líbilo, potěší nás i hodnocení.

                Budeme se těšit na shledanou.
                TXT,
        ],
    ];

    /**
     * Vzory pro předvyplnění formuláře.
     *
     * @return list<array{label: string, body: string}>
     */
    public static function templates(): array
    {
        return self::DEFAULTS;
    }

    /** @return list<QuickMessage> */
    public static function create(): array
    {
        $messages = [];
        foreach (self::DEFAULTS as $order => $default) {
            $message = new QuickMessage($default['label'], $default['body']);
            $message->setSortOrder($order);
            $messages[] = $message;
        }

        return $messages;
    }
}
