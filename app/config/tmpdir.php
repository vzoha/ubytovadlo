<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

/*
 * Workaround pro sdílené hostingy, kde systémový temp (sys_get_temp_dir(), typicky
 * /tmp) je mimo open_basedir nebo nezapisovatelný. tempnam() tam selže s
 * "file created in the system's temporary directory" → v prod to shodí např.
 * cache:clear (Symfony XliffUtils při validaci XLIFF). Přesměrujeme TMPDIR na
 * projektový var/tmp (zapisovatelný, gitignored). Platí pro CLI i web.
 *
 * Aktivuje se JEN když TMPDIR není nastaven a systémový temp je nezapisovatelný —
 * na běžných hostech (/tmp funguje) tenhle soubor nic nezmění.
 *
 * Musí běžet co nejdřív (před autoloadem/kernelem), protože sys_get_temp_dir()
 * si výsledek cachuje po prvním volání. `$projectDir` dodává volající entry point.
 *
 * @var string $projectDir
 */

$ubytovadloTmp = ($projectDir ?? \dirname(__DIR__)) . '/var/tmp';

if (false === getenv('TMPDIR') && !@is_writable(sys_get_temp_dir())) {
    if ((is_dir($ubytovadloTmp) || @mkdir($ubytovadloTmp, 0o775, true)) && is_writable($ubytovadloTmp)) {
        putenv('TMPDIR=' . $ubytovadloTmp);
        $_ENV['TMPDIR'] = $_SERVER['TMPDIR'] = $ubytovadloTmp;
    }
}

unset($ubytovadloTmp);
