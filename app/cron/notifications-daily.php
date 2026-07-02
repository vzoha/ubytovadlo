<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

/*
 * Cron wrapper (1× denně): nejdřív případná DPH připomínka (sama se zkratuje mimo
 * ~20. den) zařadí notifikaci do fronty, pak se odešle denní souhrn — tak jde
 * i DPH připomínka v režimu „souhrn" ven týž den. Oba kroky jsou idempotentní.
 */

$run = require __DIR__ . '/_kernel.php';

$vat = $run('app:vat:remind');
$digest = $run('app:notifications:digest');

exit($vat !== 0 ? $vat : $digest);
