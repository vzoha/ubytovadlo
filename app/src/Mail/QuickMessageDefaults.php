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
            'body' => 'Dobrý den {{ guest_first_name_vocative }}, děkujeme za rezervaci '
                . '{{ check_in }} — {{ check_out }}. Těšíme se na vás! Pár dní před příjezdem '
                . 'se ozveme s podrobnostmi k cestě a předání klíčů.',
        ],
        [
            'label' => 'Online check-in',
            'body' => 'Dobrý den {{ guest_first_name_vocative }}, před příjezdem prosím vyplňte '
                . 'online check-in — potřebujeme ho kvůli povinné evidenci hostů: '
                . '{{ checkin_url }} Odkaz vede rovnou na vaši rezervaci, stačí ho otevřít. Děkujeme!',
        ],
        [
            'label' => 'Pokyny k příjezdu',
            'body' => 'Dobrý den {{ guest_first_name_vocative }}, příjezd {{ check_in }} '
                . 'od {{ check_in_time }}, odjezd {{ check_out }} do {{ check_out_time }}. '
                . 'Adresa: {{ accommodation_address }}. Kdyby cokoli, ozvěte se.',
        ],
        [
            'label' => 'Poděkování po pobytu',
            'body' => 'Dobrý den {{ guest_first_name_vocative }}, děkujeme za návštěvu — '
                . 'doufáme, že jste si pobyt užili. Budeme rádi za hodnocení a kdykoli '
                . 'se k nám můžete vrátit.',
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
