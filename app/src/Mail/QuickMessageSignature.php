<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rozloučení připojené na konec každé rychlé zprávy. Drží se v nastavení
 * (klíč `quick_message.signature`), takže stejný text nemusí být v každé zprávě
 * zvlášť a změna jména či telefonu je práce na jednom místě. Smí obsahovat
 * proměnné — dosadí se stejně jako v těle zprávy.
 *
 * Nenastavená instance dostane {@see self::DEFAULT_SIGNATURE}; prázdná hodnota
 * znamená zprávy bez podpisu.
 */
final class QuickMessageSignature
{
    public const KEY = 'quick_message.signature';

    public const DEFAULT_SIGNATURE = <<<'TXT'
        S pozdravem
        {{ accommodation_name }}
        TXT;

    private const NOTE = 'Podpis rychlých zpráv.';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Uložený podpis; výchozí text, dokud si ho provozovatel nenastavil. */
    public function current(): string
    {
        return $this->settings->getString(self::KEY) ?? self::DEFAULT_SIGNATURE;
    }

    public function save(string $signature): void
    {
        $this->settings->set(self::KEY, trim($signature), self::NOTE);
        $this->em->flush();
    }
}
