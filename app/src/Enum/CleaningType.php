<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Typ úklidu — kdo uklízí a jak se účtuje. Hodnoty jsou obecné (OSS), konkrétní
 * jména osob/firem této instance se drží jako konfigurovatelné labely v nastavení
 * (Setting `cleaning.<value>.label`), ne v kódu. Zobrazení řeš přes `cleaning_label`
 * Twig filtr / CleaningTypeLabeler, který override z nastavení zohlední.
 */
enum CleaningType: string
{
    /** Uklízí provozovatel — náklad se počítá (oportunita), ale hotovost se nevyplácí. */
    case OWNER = 'owner';
    /** Placená úklidová síla, paušál. */
    case CLEANER = 'cleaner';
    /** Placená úklidová síla včetně praní prádla. */
    case CLEANER_LAUNDRY = 'cleaner_laundry';
    /** Externí úklid (firma), cena se zadává ručně. */
    case EXTERNAL = 'external';

    /**
     * Obecný (neutrální) název. Instance si může přepsat zobrazení přes nastavení.
     */
    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Vlastní úklid',
            self::CLEANER => 'Úklid',
            self::CLEANER_LAUNDRY => 'Úklid + praní',
            self::EXTERNAL => 'Externí',
        };
    }

    /**
     * Vyplácí se hotovost? U vlastního úklidu provozovatele ne (jen evidujeme náklad).
     */
    public function defaultPayout(): bool
    {
        return $this !== self::OWNER;
    }
}
