<?php

declare(strict_types=1);

/* Cron wrapper: čte automatizační schránku (Booking/Airbnb). Konfigurace v app/.env.local. */

$run = require __DIR__ . '/_kernel.php';

exit($run('app:imap:poll'));
