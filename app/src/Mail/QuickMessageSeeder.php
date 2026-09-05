<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Repository\QuickMessageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Založení výchozí sady rychlých zpráv. Zapisuje jen do prázdného seznamu,
 * takže vlastní zprávy provozovatele nikdy nepřepíše ani nedoplní o duplikáty.
 */
final class QuickMessageSeeder
{
    public function __construct(
        private readonly QuickMessageRepository $messages,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Počet založených zpráv; 0 když už nějaké existují. */
    public function seedIfEmpty(): int
    {
        if ($this->messages->findOrdered() !== []) {
            return 0;
        }

        $defaults = QuickMessageDefaults::create();
        foreach ($defaults as $message) {
            $this->em->persist($message);
        }
        $this->em->flush();

        return \count($defaults);
    }
}
