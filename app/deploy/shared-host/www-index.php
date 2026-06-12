<?php

use App\Kernel;

/*
 * Front controller pro deploy na sdílený hosting.
 *
 * DocumentRoot subdomény je fixně ~/www, ale Symfony aplikace
 * žije vedle ní v ~/src/app (git clone). Standardní public/index.php počítá
 * project root jako rodiče public/ (dirname(__DIR__)), což by tady ukázalo na
 * ~ místo na app. Tenhle shim cestu opravuje.
 *
 * Pokud je clone jinde než v ~/src, nastav absolutní cestu přes env proměnnou
 * DEPLOY_APP_DIR (např. `SetEnv DEPLOY_APP_DIR /…/app` v ~/www/.htaccess).
 *
 * Tento soubor do ~/www kopíruje `composer deploy-www`
 * (deploy/shared-host/sync-www.php) — needituj ho přímo na serveru.
 */

$appDir = getenv('DEPLOY_APP_DIR') ?: getenv('HUKOT_APP_DIR') ?: dirname(__DIR__) . '/src/app';

require_once $appDir . '/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
