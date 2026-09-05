<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

/*
 * Cron wrapper: srovná stav rezervací s kalendářem (probíhá / dokončeno) a doplní
 * automatické akce na časovou osu nadcházejících rezervací. Pořadí je závazné —
 * dokončenému pobytu se akce už neplánují. Oba kroky jsou idempotentní.
 */

$run = require __DIR__ . '/_kernel.php';

$advance = $run('app:reservations:advance');
$plan = $run('app:actions:plan');

exit($advance !== 0 ? $advance : $plan);
