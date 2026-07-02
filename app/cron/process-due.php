<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

/*
 * Cron wrapper (á 15 min): vyhodnotí naplánované akce rezervací, kterým nadešel
 * čas (zprávy hostům, self-resolving připomínky, Ubyport), a hned pak rozešle
 * okamžité notifikace ubytovateli z fronty — i ty, které během běhu vznikly
 * (např. „selhalo odeslání zprávy hostovi"). Oba kroky jsou idempotentní.
 */

$run = require __DIR__ . '/_kernel.php';

$actions = $run('app:actions:run');
$dispatch = $run('app:notifications:dispatch');

exit($actions !== 0 ? $actions : $dispatch);
