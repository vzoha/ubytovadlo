<?php

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Invoice\SpaydGenerator;
use PHPUnit\Framework\TestCase;

class SpaydGeneratorTest extends TestCase
{
    public function testBankAccountToIban(): void
    {
        $g = new SpaydGenerator();
        // 19-2000145399/0800: bank ČS (0800), s prefixem 19. Kontrolní suma 65 dle ISO MOD-97-10.
        self::assertSame('CZ6508000000192000145399', $g->bankAccountToIban('19-2000145399/0800'));
    }

    public function testBankAccountToIbanWithPrefix(): void
    {
        $g = new SpaydGenerator();
        $iban = $g->bankAccountToIban('19-1234567/0100');
        self::assertSame('CZ', substr($iban, 0, 2));
        self::assertSame('0100', substr($iban, 4, 4));
        self::assertSame('000019', substr($iban, 8, 6));
        self::assertSame('0001234567', substr($iban, 14, 10));
    }

    public function testGenerateBasicSpayd(): void
    {
        $g = new SpaydGenerator();
        $spayd = $g->generate(
            'CZ6508000000192000145399',
            '1000',
            'CZK',
            '2026012',
            'Faktura 2026012',
        );
        self::assertStringStartsWith('SPD*1.0*ACC:CZ6508000000192000145399*AM:1000.00*CC:CZK', $spayd);
        self::assertStringContainsString('X-VS:2026012', $spayd);
        self::assertStringContainsString('MSG:Faktura 2026012', $spayd);
    }

    public function testGenerateTransliteratesMessage(): void
    {
        $g = new SpaydGenerator();
        $spayd = $g->generate('CZ6508000000192000145399', '500', 'CZK', null, 'Záloha pobyt');
        // mPDF qrcode lib má bug s vícebajtovým UTF-8 — diakritiku přepisujeme do ASCII.
        self::assertStringContainsString('MSG:Zaloha pobyt', $spayd);
    }

    public function testRejectsInvalidAccount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SpaydGenerator())->bankAccountToIban('blabla');
    }
}
