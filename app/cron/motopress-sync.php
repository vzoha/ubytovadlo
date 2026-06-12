<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

/* Cron wrapper: import rezervací z vlastního webu (MotoPress REST). */

$run = require __DIR__ . '/_kernel.php';

exit($run('app:motopress:sync'));
