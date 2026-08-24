<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

/*
 * Cron wrapper (á 15 min): stáhne data z kanálů, na které se ptáme sami —
 * rezervace z vlastního webu (MotoPress REST) a obsazenost z OTA iCal feedů
 * (Booking, Airbnb, eChalupy, CS chalupy). Kanály chodící e-mailem obsluhuje
 * `imap-poll.php`. Oba kroky jsou idempotentní; každý běží nezávisle a ven
 * propadne první nenulový návratový kód (pro monitoring).
 */

$run = require __DIR__ . '/_kernel.php';

$motopress = $run('app:motopress:sync');
$ical = $run('app:ical:sync');

exit($motopress !== 0 ? $motopress : $ical);
