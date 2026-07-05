<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Connector;

use App\Credential\CredentialProvider;
use App\Entity\Connector;
use App\Enum\ConnectorStatus;
use App\Enum\ConnectorType;
use App\Repository\ConnectorRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Přístupový bod ke stavu konektorů: zda je zapnutý (pollery to respektují),
 * zápis výsledku běhu a poslední aktivity a sestavení přehledu zdraví pro UI.
 */
class ConnectorManager
{
    /** Klíč v Connector::config, pod kterým žije URL iCal feedu obsazenosti. */
    public const ICAL_FEED_URL_KEY = 'ical_import_url';

    public function __construct(
        private readonly ConnectorRepository $connectors,
        private readonly CredentialProvider $credentials,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Zapnutý-li konektor. Chybějící řádek = zapnuto (default), bez zápisu. */
    public function isEnabled(ConnectorType $type): bool
    {
        return $this->connectors->findOneBy(['type' => $type])?->isEnabled() ?? true;
    }

    public function setEnabled(ConnectorType $type, bool $enabled): void
    {
        $this->connectors->getOrCreate($type)->setEnabled($enabled);
        $this->em->flush();
    }

    /** Zaznamená výsledek běhu konektoru a uloží. */
    public function recordRun(ConnectorType $type, ConnectorStatus $status, ?string $error = null): void
    {
        $this->connectors->getOrCreate($type)->recordRun($status, $error);
        $this->em->flush();
    }

    /**
     * Posune značku poslední aktivity (dorazila data). Samotný zápis nechává na
     * volajícím — uvnitř transakce (dispatcher) nebo navazujícím recordRun (command);
     * flush proběhne jen když se řádek konektoru zakládá poprvé (getOrCreate).
     */
    public function recordActivity(ConnectorType $type, ?\DateTimeImmutable $when = null): void
    {
        $this->connectors->getOrCreate($type)->recordActivity($when ?? new \DateTimeImmutable());
    }

    /**
     * Má konektor vyplněné přístupy? MotoPress přes REST, e-mailové přes IMAP,
     * iCal-only konektory (eChalupy, CS chalupy) přes URL feedu.
     */
    public function isConfigured(ConnectorType $type): bool
    {
        return match (true) {
            $type === ConnectorType::MOTOPRESS => $this->credentials->motopressConfigured(),
            $type->usesImap() => $this->credentials->imapConfigured(),
            $type->supportsIcalImport() => $this->getFeedUrl($type) !== null,
            default => false,
        };
    }

    /** URL iCal feedu konektoru, nebo null. Nezakládá řádek (jen čte). */
    public function getFeedUrl(ConnectorType $type): ?string
    {
        return $this->connectors->findOneBy(['type' => $type])?->getConfigValue(self::ICAL_FEED_URL_KEY);
    }

    public function setFeedUrl(ConnectorType $type, ?string $url): void
    {
        $this->connectors->getOrCreate($type)->setConfigValue(self::ICAL_FEED_URL_KEY, $url);
        $this->em->flush();
    }

    /**
     * Přehled zdraví všech konektorů pro UI (v pořadí enumu). Chybějící řádky se
     * hodnotí jako výchozí (zapnuto, čeká) — nezakládají se pouhým zobrazením.
     *
     * @return list<ConnectorHealth>
     */
    public function health(): array
    {
        $now = new \DateTimeImmutable();

        $existing = [];
        foreach ($this->connectors->findAll() as $connector) {
            $existing[$connector->getType()->value] = $connector;
        }

        $health = [];
        foreach (ConnectorType::cases() as $type) {
            $connector = $existing[$type->value] ?? new Connector($type);
            $health[] = ConnectorHealth::assess($connector, $this->isConfigured($type), $now);
        }

        return $health;
    }
}
