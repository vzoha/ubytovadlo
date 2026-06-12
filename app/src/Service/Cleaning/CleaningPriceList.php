<?php

declare(strict_types=1);

namespace App\Service\Cleaning;

use App\Enum\CleaningType;
use App\Repository\SettingRepository;

/**
 * Ceník úklidu. Cena se počítá jednotně pro všechny typy podle počtu hostů:
 * do prahu (včetně) platí nižší cena, nad práh vyšší. Paušál se modeluje tak,
 * že obě ceny jsou stejné. Hodnoty žijí v DB (tabulka setting), klíče:
 *   cleaning.<type>.threshold_guests   (práh hostů)
 *   cleaning.<type>.price_small         (cena do prahu včetně)
 *   cleaning.<type>.price_large         (cena nad práh)
 * Bez nastavení se použijí defaulty z DEFAULTS.
 */
final class CleaningPriceList
{
    /** @var array<string, array{threshold: int, small: int, large: int}> per-type výchozí ceník */
    private const DEFAULTS = [
        'owner' => ['threshold' => 2, 'small' => 400, 'large' => 600],
        'cleaner' => ['threshold' => 2, 'small' => 700, 'large' => 700],
        'cleaner_laundry' => ['threshold' => 2, 'small' => 1000, 'large' => 1000],
        'external' => ['threshold' => 2, 'small' => 0, 'large' => 0],
    ];

    public function __construct(private readonly SettingRepository $settings)
    {
    }

    public function costFor(CleaningType $type, int $guestsTotal): int
    {
        $defaults = self::defaultsFor($type);
        $threshold = $this->settings->getInt($this->thresholdKey($type), $defaults['threshold']);
        $small = $this->settings->getInt($this->smallKey($type), $defaults['small']);
        $large = $this->settings->getInt($this->largeKey($type), $defaults['large']);

        return $guestsTotal > $threshold ? $large : $small;
    }

    public function payoutFor(CleaningType $type, int $cost): int
    {
        return $type->defaultPayout() ? $cost : 0;
    }

    /**
     * @return array{threshold: int, small: int, large: int}
     */
    public static function defaultsFor(CleaningType $type): array
    {
        return self::DEFAULTS[$type->value];
    }

    public function thresholdKey(CleaningType $type): string
    {
        return 'cleaning.' . $type->value . '.threshold_guests';
    }

    public function smallKey(CleaningType $type): string
    {
        return 'cleaning.' . $type->value . '.price_small';
    }

    public function largeKey(CleaningType $type): string
    {
        return 'cleaning.' . $type->value . '.price_large';
    }
}
