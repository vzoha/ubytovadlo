<?php

declare(strict_types=1);

namespace App\Ubyport;

use Smalot\PdfParser\Parser as PdfParser;

/**
 * Čte doručenku Ubyportu (PDF "doručenka elektronického oznámení ubytování
 * cizinců ubytovatelem") a vytáhne počty záznamů + IDUB. Slouží ke kontrole,
 * že nahlášení proběhlo a počet přijatých sedí na počet hlášených cizinců.
 *
 * Doručenka má v hlavičce řádky typu:
 *   IDUB: 100000000001
 *   Celkový počet záznamů: 4    Počet ignorovaných záznamů: 0
 *   Seznam nepřijatých záznamů: 0
 *   Počet přijatých záznamů: 4
 *
 * pdfparser ovšem text sype bez mezer mezi slovy ("Početpřijatýchzáznamů: 4"),
 * proto před matchováním veškerý whitespace odstraníme úplně a popisky hledáme
 * v kompaktní podobě. "SEZNAM NEPŘIJATÝCH ZÁZNAMŮ" (verzálky, bez čísla) je
 * nadpis sekce — regex na "Seznamnepřijatýchzáznamů:<číslo>" se na něj nechytí.
 */
final class UbyportReceiptParser
{
    public function __construct(private readonly PdfParser $pdfParser = new PdfParser())
    {
    }

    public function parseFile(string $path): UbyportReceiptData
    {
        return $this->parseText($this->pdfParser->parseFile($path)->getText());
    }

    public function parseText(string $text): UbyportReceiptData
    {
        $text = preg_replace('/[\s\x{00A0}]+/u', '', $text) ?? $text;

        $accepted = $this->matchInt($text, '/Početpřijatýchzáznamů:(\d+)/u');
        if ($accepted === null) {
            throw new \InvalidArgumentException('PDF nevypadá jako doručenka Ubyportu (nenašel jsem "Počet přijatých záznamů").');
        }

        return new UbyportReceiptData(
            idub: $this->matchString($text, '/IDUB:(\d+)/u'),
            total: $this->matchInt($text, '/Celkovýpočetzáznamů:(\d+)/u') ?? $accepted,
            accepted: $accepted,
            rejected: $this->matchInt($text, '/Seznamnepřijatýchzáznamů:(\d+)/u') ?? 0,
            ignored: $this->matchInt($text, '/Početignorovanýchzáznamů:(\d+)/u') ?? 0,
        );
    }

    private function matchInt(string $text, string $pattern): ?int
    {
        $value = $this->matchString($text, $pattern);

        return $value === null ? null : (int) $value;
    }

    private function matchString(string $text, string $pattern): ?string
    {
        if (preg_match($pattern, $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
