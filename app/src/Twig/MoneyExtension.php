<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class MoneyExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->money(...)),
        ];
    }

    /**
     * Formátuje částku v českém formátu (1 234,50) a připojí symbol měny.
     * CZK se renderuje jako "Kč", ostatní jako ISO kód.
     */
    public function money(float|int|string|null $amount, ?string $currency = 'CZK', int $decimals = 2): string
    {
        $amount ??= 0;
        $formatted = number_format((float) $amount, $decimals, ',', ' ');
        $symbol = $currency === 'CZK' ? 'Kč' : (string) $currency;

        return trim($formatted . ' ' . $symbol);
    }
}
