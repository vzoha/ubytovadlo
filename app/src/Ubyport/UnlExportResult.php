<?php

declare(strict_types=1);

namespace App\Ubyport;

/**
 * Výstup UnlExporter::build() — soubor připravený k uploadu na ubyport.policie.cz.
 * `content` jsou syrové bajty v Windows-1250 (zápis na disk bez další konverze).
 */
final readonly class UnlExportResult
{
    public function __construct(
        public string $content,
        public string $filename,
        public int $guestCount,
    ) {
    }
}
