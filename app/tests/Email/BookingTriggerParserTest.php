<?php

declare(strict_types=1);

namespace App\Tests\Email;

use App\Email\BookingTriggerParser;
use App\Email\EmailMessage;
use App\Email\EmlReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingTriggerParserTest extends TestCase
{
    private BookingTriggerParser $parser;
    private EmlReader $reader;

    protected function setUp(): void
    {
        $this->parser = new BookingTriggerParser();
        $this->reader = new EmlReader();
    }

    /**
     * @return list<array{string, string, string}>
     */
    public static function fixtureProvider(): array
    {
        return [
            ['Booking.com - Nová rezervace! (7000000001, pondělí 15. dubna 2026).eml', '7000000001', '2026-04-15'],
            ['Booking.com - Nová rezervace! (7000000002, pátek 3. května 2026).eml', '7000000002', '2026-05-03'],
            ['Booking.com - Nová rezervace! (7000000003, neděle 12. května 2026).eml', '7000000003', '2026-05-12'],
            ['Booking.com - Nová rezervace! (7000000004, pátek 22. března 2026).eml', '7000000004', '2026-03-22'],
            ['Booking.com - Nová rezervace! (7000000005, čtvrtek 4. dubna 2026).eml', '7000000005', '2026-04-04'],
        ];
    }

    #[DataProvider('fixtureProvider')]
    public function testParsesAllFixtures(string $file, string $expectedId, string $expectedDate): void
    {
        $email = $this->reader->fromFile(__DIR__ . '/../Fixtures/Booking/' . $file);

        self::assertTrue($this->parser->supports($email));

        $data = $this->parser->parse($email);

        self::assertSame($expectedId, $data->reservationId);
        self::assertSame($expectedDate, $data->checkIn->format('Y-m-d'));
    }

    public function testRejectsUnrelatedSender(): void
    {
        $email = new EmailMessage(
            messageId: 'foo@bar',
            fromAddress: 'automated@airbnb.com',
            subject: 'Rezervace potvrzena - X přijede 7. 9.',
            date: new \DateTimeImmutable(),
            textBody: '',
        );

        self::assertFalse($this->parser->supports($email));
    }
}
