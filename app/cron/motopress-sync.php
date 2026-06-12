<?php

declare(strict_types=1);

/* Cron wrapper: import rezervací z vlastního webu (MotoPress REST). */

$run = require __DIR__ . '/_kernel.php';

exit($run('app:motopress:sync'));
