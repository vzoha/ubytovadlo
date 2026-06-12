<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Překládá mezi relativní cestou PDF uloženou v DB (např.
 * `var/invoices/2026/2026013.pdf`) a absolutní cestou na disku.
 *
 * Relativní cesta je nezávislá na prostředí (docker `/app` vs sdílený hosting
 * `<home>/src/app`), takže `mysqldump` přenese data mezi
 * prostředími bez ručního přepisování cest.
 */
class PdfStorage
{
    public function __construct(private readonly string $projectDir)
    {
    }

    /** Absolutní cesta k souboru z uložené (relativní) cesty. */
    public function absolute(string $storedPath): string
    {
        if ($storedPath === '') {
            return '';
        }

        // Zpětná kompatibilita: starší záznamy mohou mít absolutní cestu.
        if (str_starts_with($storedPath, '/')) {
            return $storedPath;
        }

        return $this->projectDir . '/' . $storedPath;
    }

    /** Relativní cesta (vůči projectDir) z absolutní cesty na disku. */
    public function relative(string $absolutePath): string
    {
        $prefix = $this->projectDir . '/';
        if (str_starts_with($absolutePath, $prefix)) {
            return substr($absolutePath, strlen($prefix));
        }

        return $absolutePath;
    }
}
