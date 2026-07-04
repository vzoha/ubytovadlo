<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Setup;

/**
 * Jedna položka onboarding checklistu na dashboardu: co je vhodné nastavit,
 * kam pro to jít, jestli už je to hotové a jestli si ji provozovatel skryl.
 */
final readonly class SetupChecklistItem
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $route,
        public bool $configured,
        public bool $dismissed,
    ) {
    }
}
