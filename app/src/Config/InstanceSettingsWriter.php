<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Config;

use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Uloží hodnoty formuláře identity instance (GeneralSettingsType) do Settings
 * a případné nahrané logo. Sdílené samostatnou stránkou nastavení i průvodcem.
 */
final class InstanceSettingsWriter
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly LogoStorage $logo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param FormInterface<mixed> $form */
    public function save(FormInterface $form): void
    {
        $this->settings->set(InstanceSettings::KEY_BRAND_NAME, trim((string) $form->get('brandName')->getData()), 'Název instance (brand).');
        $this->settings->set(InstanceSettings::KEY_BASE_URL, trim((string) $form->get('baseUrl')->getData()), 'Veřejná adresa aplikace pro odkazy v e-mailech.');
        $this->em->flush();

        $logoFile = $form->get('logoFile')->getData();
        if ($logoFile instanceof UploadedFile) {
            $this->logo->store($logoFile);
        }
    }
}
