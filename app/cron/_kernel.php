<?php

declare(strict_types=1);

/*
 * Společný bootstrap pro cron wrappery (cron sdíleného hostingu umí spustit jen
 * PHP soubor cestou, bez argumentů — proto per-command tenký skript volá tenhle runner).
 *
 * Vrací closure, která spustí daný Symfony console command v prostředí z .env
 * (na produkci .env.local.php po `composer dump-env prod`).
 */

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Dotenv\Dotenv;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
(new Dotenv())->bootEnv($root . '/.env');

return static function (string $command): int {
    $kernel = new Kernel($_SERVER['APP_ENV'] ?? 'prod', (bool) ($_SERVER['APP_DEBUG'] ?? false));
    $application = new Application($kernel);
    $application->setAutoExit(false);

    $output = new StreamOutput(fopen('php://stdout', 'w') ?: STDOUT);

    return $application->run(new ArrayInput(['command' => $command]), $output);
};
