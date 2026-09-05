<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\MotoPress;

use App\Credential\CredentialProvider;
use App\Entity\Reservation;

/**
 * Propíše stav rezervace do MotoPressu. Obsazenost na webu drží plugin: dokud
 * je rezervace v MotoPressu potvrzená, termín zůstává blokovaný, i když je
 * v Ubytovadle zrušený — a naopak.
 */
final class MotoPressBookingStatusWriter
{
    private const STATUS_CANCELLED = 'cancelled';
    private const STATUS_CONFIRMED = 'confirmed';

    public function __construct(
        private readonly MotoPressClient $client,
        private readonly CredentialProvider $credentials,
    ) {
    }

    /**
     * Rezervace, kterou lze v MotoPressu přepsat — zná své MotoPress ID a spojení
     * je nastavené.
     */
    public function supports(Reservation $reservation): bool
    {
        return $reservation->getMotopressExternalId() !== null && $this->credentials->motopressConfigured();
    }

    /**
     * @throws MotoPressApiException když API odmítne zápis (typicky klíč jen pro čtení)
     */
    public function cancel(Reservation $reservation): void
    {
        $this->write($reservation, self::STATUS_CANCELLED);
    }

    /**
     * @throws MotoPressApiException když API odmítne zápis (typicky klíč jen pro čtení)
     */
    public function confirm(Reservation $reservation): void
    {
        $this->write($reservation, self::STATUS_CONFIRMED);
    }

    private function write(Reservation $reservation, string $status): void
    {
        $id = $reservation->getMotopressExternalId();
        if ($id === null) {
            return;
        }

        $this->client->updateBookingStatus((int) $id, $status);
    }
}
