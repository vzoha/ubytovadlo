<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\MotoPress;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tenky klient nad MotoPress Hotel Booking REST API.
 * Endpoint: {baseUrl}/wp-json/mphb/v1/...
 * Auth: HTTP Basic, consumer_key:consumer_secret (stejny pattern jako WooCommerce).
 */
class MotoPressClient
{
    private const API_PATH = '/wp-json/mphb/v1';
    private const PER_PAGE_MAX = 100;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $motopressBaseUrl,
        private readonly string $motopressConsumerKey,
        private readonly string $motopressConsumerSecret,
    ) {
    }

    /**
     * @param array<string, scalar|array<scalar>> $query
     *
     * @return list<array<string, mixed>>
     */
    public function listBookings(array $query = []): array
    {
        return $this->paginate('/bookings', $query);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listServices(): array
    {
        return $this->paginate('/accommodation_types/services', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getBooking(int $id): array
    {
        return $this->request('GET', sprintf('/bookings/%d', $id));
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomer(int $id): array
    {
        return $this->request('GET', sprintf('/customers/%d', $id));
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayment(int $id): array
    {
        return $this->request('GET', sprintf('/payments/%d', $id));
    }

    /**
     * @param array<string, scalar|array<scalar>> $query
     *
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path, array $query): array
    {
        $page = 1;
        $totalPages = 1;
        $all = [];
        do {
            $pageQuery = $query + [
                'page' => $page,
                'per_page' => self::PER_PAGE_MAX,
            ];
            [$items, $headerTotalPages] = $this->fetchPage($path, $pageQuery);
            foreach ($items as $item) {
                if (is_array($item)) {
                    $all[] = $item;
                }
            }
            if ($headerTotalPages !== null) {
                $totalPages = $headerTotalPages;
            } elseif (count($items) < self::PER_PAGE_MAX) {
                $totalPages = $page;
            }
            $page++;
        } while ($page <= $totalPages);

        return $all;
    }

    /**
     * @param array<string, scalar|array<scalar>> $query
     *
     * @return array{0: array<int|string, mixed>, 1: int|null}
     */
    private function fetchPage(string $path, array $query): array
    {
        $url = rtrim($this->motopressBaseUrl, '/') . self::API_PATH . $path;

        try {
            $response = $this->httpClient->request('GET', $url, [
                'auth_basic' => [$this->motopressConsumerKey, $this->motopressConsumerSecret],
                'query' => $query,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new MotoPressApiException(sprintf('MotoPress API GET %s vratil HTTP %d: %s', $path, $status, $response->getContent(false)));
            }

            $decoded = $response->toArray(false);
            $totalPagesHeader = $response->getHeaders(false)['x-wp-totalpages'][0] ?? null;
            $totalPages = is_string($totalPagesHeader) && ctype_digit($totalPagesHeader)
                ? (int) $totalPagesHeader
                : null;
        } catch (HttpExceptionInterface|TransportException $e) {
            $this->logger->error('MotoPress API selhalo', ['method' => 'GET', 'path' => $path, 'error' => $e->getMessage()]);
            throw new MotoPressApiException('MotoPress API request selhal: ' . $e->getMessage(), 0, $e);
        }

        return [$decoded, $totalPages];
    }

    /**
     * @param array<string, scalar|array<scalar>> $query
     *
     * @return array<int|string, mixed>
     */
    private function request(string $method, string $path, array $query = []): array
    {
        $url = rtrim($this->motopressBaseUrl, '/') . self::API_PATH . $path;

        try {
            $response = $this->httpClient->request($method, $url, [
                'auth_basic' => [$this->motopressConsumerKey, $this->motopressConsumerSecret],
                'query' => $query,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new MotoPressApiException(sprintf('MotoPress API %s %s vratil HTTP %d: %s', $method, $path, $status, $response->getContent(false)));
            }

            $decoded = $response->toArray(false);
        } catch (HttpExceptionInterface|TransportException $e) {
            $this->logger->error('MotoPress API selhalo', ['method' => $method, 'path' => $path, 'error' => $e->getMessage()]);
            throw new MotoPressApiException('MotoPress API request selhal: ' . $e->getMessage(), 0, $e);
        }

        return $decoded;
    }
}
