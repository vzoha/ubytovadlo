<?php

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Invoice\InvoiceNumber;
use PHPUnit\Framework\TestCase;

class InvoiceNumberTest extends TestCase
{
    public function testFormatPadsSequence(): void
    {
        self::assertSame('2026012', (new InvoiceNumber(2026, 12))->formatted());
        self::assertSame('2027001', (new InvoiceNumber(2027, 1))->formatted());
        self::assertSame('2026999', (new InvoiceNumber(2026, 999))->formatted());
    }

    public function testRejectsOutOfRangeSequence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InvoiceNumber(2026, 1000);
    }

    public function testRejectsZeroSequence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InvoiceNumber(2026, 0);
    }
}
