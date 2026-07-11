<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Uloží hodnoty formuláře dodavatele (IssuerSettingsType) — fakturační identitu
 * a daňový profil — do Settings. Sdílené samostatnou stránkou nastavení i průvodcem.
 */
final class IssuerSettingsWriter
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param FormInterface<mixed> $form */
    public function save(FormInterface $form): void
    {
        foreach (IssuerProfileProvider::KEYS as $field => $key) {
            $this->settings->set($key, trim((string) $form->get($field)->getData()), 'Dodavatel na faktuře.');
        }
        $this->settings->set(TaxProfileConfig::KEY, $form->get('taxProfile')->getData()->value, 'Daňový profil dodavatele.');
        $this->em->flush();
    }
}
