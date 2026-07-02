<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

/* Cron wrapper: rozešle okamžité notifikace ubytovateli z fronty. */

$run = require __DIR__ . '/_kernel.php';

exit($run('app:notifications:dispatch'));
