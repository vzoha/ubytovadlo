<?php

declare(strict_types=1);

namespace App\Vat;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Klient pro denní kurzy ČNB.
 *
 * API api.cnb.cz/cnbapi/exrates/daily samo vrací kurz pro nejbližší předchozí
 * pracovní den, pokud dotaz padne na víkend/svátek (validFor v odpovědi). Nemusíme
 * tedy v PHP řešit kalendář svátků — důvěřujeme tomu, co vrátí ČNB.
 */
class CnbExchangeRateClient
{
    private const BASE_URL = 'https://api.cnb.cz/cnbapi/exrates/daily';

    /** @var array<string, CnbRate> */
    private array $cache = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Vrátí kurz měny vůči CZK pro daný den. Pokud je den víkend/svátek, ČNB
     * automaticky vrátí kurz posledního pracovního dne před.
     *
     * Vrácený rate je už normalizovaný na 1 jednotku měny (např. HUF API vrací
     * kurz za 100 jednotek, my to přepočteme na 1).
     */
    public function getRate(string $currencyCode, \DateTimeImmutable $date): CnbRate
    {
        $cacheKey = $date->format('Y-m-d') . '|' . strtoupper($currencyCode);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $url = self::BASE_URL . '?' . http_build_query([
            'date' => $date->format('Y-m-d'),
            'lang' => 'EN',
        ]);

        $response = $this->httpClient->request('GET', $url);
        $data = $response->toArray();

        $currencyCode = strtoupper($currencyCode);
        foreach ($data['rates'] ?? [] as $row) {
            if (($row['currencyCode'] ?? null) === $currencyCode) {
                $amount = (float) ($row['amount'] ?? 1);
                $rawRate = (float) ($row['rate'] ?? 0);
                if ($amount <= 0 || $rawRate <= 0) {
                    throw new \RuntimeException(sprintf('CNB returned invalid rate for %s on %s', $currencyCode, $date->format('Y-m-d')));
                }

                $normalizedRate = $rawRate / $amount;
                $validFor = new \DateTimeImmutable($row['validFor']);
                $rate = new CnbRate($currencyCode, $normalizedRate, $validFor);
                $this->cache[$cacheKey] = $rate;

                $this->logger->debug('CNB rate fetched', [
                    'currency' => $currencyCode,
                    'requestedDate' => $date->format('Y-m-d'),
                    'validFor' => $validFor->format('Y-m-d'),
                    'rate' => $normalizedRate,
                ]);

                return $rate;
            }
        }

        throw new \RuntimeException(sprintf('CNB rate for %s on %s not found', $currencyCode, $date->format('Y-m-d')));
    }
}
