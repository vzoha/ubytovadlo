<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Currency;

use App\Vat\CnbExchangeRateClient;
use App\Vat\CnbRate;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Denní kurz ČNB pro orientační zobrazení — kurz dne se drží v cache, takže
 * stránka sáhne na API nejvýš jednou za den a měnu. Nedostupné API vrací null;
 * zobrazení bez kurzu je v pořádku, spadlá stránka ne.
 *
 * Kurzy, kterými se počítá daň nebo faktura, sem nepatří — ty se ukládají
 * k rezervaci a faktuře přes `App\Vat\VatCalculator` a `App\Invoice\InvoiceService`.
 */
final class DailyCnbRateProvider
{
    private const TTL_RATE = 86400;
    private const TTL_UNAVAILABLE = 900;

    public function __construct(
        private readonly CnbExchangeRateClient $cnb,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function rate(string $currency, \DateTimeImmutable $day): ?CnbRate
    {
        $currency = strtoupper($currency);
        $key = sprintf('cnb_daily_rate.%s.%s', $currency, $day->format('Y-m-d'));

        $rate = $this->cache->get($key, function (ItemInterface $item) use ($currency, $day): ?CnbRate {
            try {
                $rate = $this->cnb->getRate($currency, $day);
                $item->expiresAfter(self::TTL_RATE);

                return $rate;
            } catch (\Throwable $e) {
                // Krátká platnost i pro neúspěch, ať výpadek ČNB neznamená dotaz
                // při každém načtení seznamu.
                $item->expiresAfter(self::TTL_UNAVAILABLE);
                $this->logger->warning('CNB daily rate unavailable', [
                    'currency' => $currency,
                    'day' => $day->format('Y-m-d'),
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });

        return $rate instanceof CnbRate ? $rate : null;
    }
}
