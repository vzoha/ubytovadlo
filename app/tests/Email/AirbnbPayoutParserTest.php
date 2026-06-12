<?php

declare(strict_types=1);

namespace App\Tests\Email;

use App\Email\AirbnbPayoutParser;
use App\Email\EmlReader;
use PHPUnit\Framework\TestCase;

final class AirbnbPayoutParserTest extends TestCase
{
    private AirbnbPayoutParser $parser;
    private EmlReader $reader;

    protected function setUp(): void
    {
        $this->parser = new AirbnbPayoutParser();
        $this->reader = new EmlReader();
    }

    public function testParsesIrisGepplPayout(): void
    {
        $email = $this->reader->fromFile(__DIR__ . '/../Fixtures/Airbnb/poslali-jsme-ti-vyplatu-eva-markova-2500.eml');

        self::assertTrue($this->parser->supports($email));

        $p = $this->parser->parse($email);

        self::assertSame('HMMNOP56QR', $p->confirmationCode);
        self::assertSame(2500.0, $p->payoutAmount);
        self::assertSame('2026-05-27', $p->payoutSentAt->format('Y-m-d'));
        self::assertSame('2026-06-03', $p->payoutExpectedAt?->format('Y-m-d'));
        self::assertSame('G-XY12345678901', $p->payoutReference);
        self::assertSame('Eva Marková', $p->guestName);
    }

    public function testDoesNotSupportReservationConfirmation(): void
    {
        $email = $this->reader->fromFile(__DIR__ . '/../Fixtures/Airbnb/rezervace-potvrzena-petr-novak-pijede-3-9.eml');

        self::assertFalse($this->parser->supports($email));
    }
}
