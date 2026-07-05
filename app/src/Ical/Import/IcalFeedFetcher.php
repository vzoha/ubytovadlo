<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Ical\Import;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stáhne obsah veřejného iCal feedu OTA (Airbnb/Booking/eChalupy/CS chalupy).
 * Vrací syrový text kalendáře; na nedostupnost nebo chybný status vyhodí
 * {@see IcalFeedException}, aby command mohl zapsat chybu do stavu konektoru.
 */
final class IcalFeedFetcher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetch(string $url): string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'text/calendar, text/plain, */*'],
                'timeout' => 30,
                'max_redirects' => 3,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new IcalFeedException(sprintf('iCal feed vrátil HTTP %d.', $status));
            }

            return $response->getContent(false);
        } catch (HttpClientException $e) {
            $this->logger->error('Stažení iCal feedu selhalo', ['url' => $url, 'error' => $e->getMessage()]);

            throw new IcalFeedException('iCal feed je nedostupný: ' . $e->getMessage(), 0, $e);
        }
    }
}
