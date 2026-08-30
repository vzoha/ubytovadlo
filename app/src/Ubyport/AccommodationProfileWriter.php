<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Ubyport;

use App\Entity\AccommodationProfile;
use App\Repository\AccommodationProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Čtení a zápis údajů ubytovacího zařízení (singleton). Sdílené samostatnou
 * stránkou nastavení i průvodcem, aby zápis žil na jednom místě.
 */
final class AccommodationProfileWriter
{
    public function __construct(
        private readonly AccommodationProfileRepository $profiles,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Uložený profil, nebo prázdný k vyplnění. */
    public function current(): AccommodationProfile
    {
        return $this->profiles->getSingleton() ?? new AccommodationProfile();
    }

    public function save(AccommodationProfile $profile): void
    {
        if ($profile->getId() === null) {
            $this->em->persist($profile);
        }
        $this->em->flush();
    }
}
