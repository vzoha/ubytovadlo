<?php

/*
 * Dorovná DocumentRoot sdíleného hostingu (~/www) podle aktuálního buildu aplikace.
 *
 * Spouští se na serveru přes `composer deploy-www` z project rootu (~/src/app)
 * po `git pull` + `composer install --no-dev`. Dělá:
 *   1) zrcadlí public/ → ~/www (assets, bundles, robots, … KROMĚ index.php/.htaccess)
 *   2) nakopíruje deploy/shared-host/www-index.php → ~/www/index.php (shim)
 *   3) nakopíruje deploy/shared-host/htaccess     → ~/www/.htaccess
 *
 * Cíl ~/www se odvodí z umístění aplikace; lze přepsat env DEPLOY_WWW_DIR.
 * V dev prostředí (kde ~/www neexistuje) skript bezpečně skončí chybou.
 */

$projectRoot = dirname(__DIR__, 2);          // …/src/app
$deployDir = __DIR__;                       // …/src/app/deploy/shared-host
$publicDir = $projectRoot . '/public';
$wwwDir = getenv('DEPLOY_WWW_DIR') ?: getenv('HUKOT_WWW_DIR') ?: dirname($projectRoot, 2) . '/www';

fwrite(STDOUT, "deploy-www: project={$projectRoot}\n");
fwrite(STDOUT, "deploy-www: www={$wwwDir}\n");

if (!is_dir($wwwDir)) {
    fwrite(STDERR, "CHYBA: cílový adresář '{$wwwDir}' neexistuje.\n");
    fwrite(STDERR, "Spouštěj na sdíleném hostingu (DocumentRoot ~/www), nebo nastav DEPLOY_WWW_DIR.\n");
    exit(1);
}
if (!is_dir($publicDir)) {
    fwrite(STDERR, "CHYBA: '{$publicDir}' neexistuje — chybí build?\n");
    exit(1);
}

$rrmdir = static function (string $dir) use (&$rrmdir): void {
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        is_dir($p) && !is_link($p) ? $rrmdir($p) : unlink($p);
    }
    rmdir($dir);
};

$rcopy = static function (string $src, string $dst) use (&$rcopy): void {
    if (is_dir($src)) {
        @mkdir($dst, 0o775, true);
        foreach (scandir($src) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $rcopy($src . '/' . $e, $dst . '/' . $e);
        }

        return;
    }
    copy($src, $dst);
};

// 1) zrcadlení public/ → www (kromě front controlleru, ten řeší shim)
$skip = ['index.php', '.htaccess', '.', '..'];
foreach (scandir($publicDir) as $entry) {
    if (in_array($entry, $skip, true)) {
        continue;
    }
    $src = $publicDir . '/' . $entry;
    $dst = $wwwDir . '/' . $entry;
    if (is_dir($src) && is_dir($dst)) {
        $rrmdir($dst);            // dropni starý obsah, ať nezůstávají smazané soubory
    } elseif (is_file($dst)) {
        unlink($dst);
    }
    $rcopy($src, $dst);
    fwrite(STDOUT, "  ~ www/{$entry}\n");
}

// 2) + 3) shim a .htaccess
copy($deployDir . '/www-index.php', $wwwDir . '/index.php');
copy($deployDir . '/htaccess', $wwwDir . '/.htaccess');
fwrite(STDOUT, "  + www/index.php (shim)\n  + www/.htaccess\n");

fwrite(STDOUT, "deploy-www: hotovo.\n");
