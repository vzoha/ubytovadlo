<?php

declare(strict_types=1);

namespace App\Tests\Ubyport;

use App\Ubyport\UbyportReceiptParser;
use PHPUnit\Framework\TestCase;

final class UbyportReceiptParserTest extends TestCase
{
    private UbyportReceiptParser $parser;

    protected function setUp(): void
    {
        $this->parser = new UbyportReceiptParser();
    }

    /** Text odpovídá reálné doručence (20260530115158_UbyUp_K9MFC.pdf, 4 přijaté). */
    private const RECEIPT_TEXT = <<<'TXT'
        Internetová aplikace Ubyport - doručenka elektronického oznámení ubytování cizinců ubytovatelem
        IDUB: 100000000001 Zkratka: TESTX Datum doručení oznámení:30.05.2026 11:51
        Uživatel: ub0000000
        Ubytovací zařízení : APARTMÁN UKÁZKA
        Celkový počet záznamů: 4 Počet ignorovaných záznamů: 0
        Seznam nepřijatých záznamů: 0
        Počet přijatých záznamů: 4
        SEZNAM NEPŘIJATÝCH ZÁZNAMŮ
        TXT;

    public function testParsesCounts(): void
    {
        $data = $this->parser->parseText(self::RECEIPT_TEXT);

        self::assertSame('100000000001', $data->idub);
        self::assertSame(4, $data->total);
        self::assertSame(4, $data->accepted);
        self::assertSame(0, $data->rejected);
        self::assertSame(0, $data->ignored);
        self::assertTrue($data->isAllAccepted());
    }

    public function testRejectedRecordsAreNotAllAccepted(): void
    {
        $text = str_replace(
            ['Celkový počet záznamů: 4', 'Seznam nepřijatých záznamů: 0', 'Počet přijatých záznamů: 4'],
            ['Celkový počet záznamů: 4', 'Seznam nepřijatých záznamů: 1', 'Počet přijatých záznamů: 3'],
            self::RECEIPT_TEXT,
        );

        $data = $this->parser->parseText($text);

        self::assertSame(3, $data->accepted);
        self::assertSame(1, $data->rejected);
        self::assertFalse($data->isAllAccepted());
    }

    public function testNonReceiptThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parseText('Tohle není doručenka.');
    }
}
