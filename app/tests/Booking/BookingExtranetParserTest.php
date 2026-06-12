<?php

declare(strict_types=1);

namespace App\Tests\Booking;

use App\Booking\BookingExtranetParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingExtranetParserTest extends TestCase
{
    public function testParsesJanRyskaPaste(): void
    {
        $raw = (string) file_get_contents(__DIR__ . '/../Fixtures/Booking/extranet-paste-karel-novotny.txt');

        $data = (new BookingExtranetParser())->parse($raw);

        self::assertSame('2026-05-12', $data->checkIn?->format('Y-m-d'));
        self::assertSame('2026-05-16', $data->checkOut?->format('Y-m-d'));
        self::assertSame(2, $data->guestsAdult);
        self::assertNull($data->guestsChild);
        self::assertSame('300.00', $data->priceTotal);
        self::assertSame('EUR', $data->priceCurrency);
        self::assertSame('7000000003', $data->externalId);
        self::assertSame('45.00', $data->commissionAmount);
        self::assertSame('EUR', $data->commissionCurrency);
        self::assertSame('Karel Novotný', $data->guestName);
        self::assertSame('knovotny.100001@guest.booking.com', $data->guestEmail);
        self::assertSame('+420700000000', $data->guestPhone);
        self::assertSame('Květná 5', $data->guestStreet);
        self::assertSame('Lipov', $data->guestCity);
        self::assertSame('696 51', $data->guestZip);
        self::assertSame('CZ', $data->guestCountry);
        self::assertSame('+ 1 pes (tornjak)', $data->notes);
        self::assertTrue($data->hasPet);
        self::assertSame('tornjak', $data->petsNote);
    }

    public function testParsesAdultsAndChildren(): void
    {
        $raw = "Celkový počet hostů\n2 dospělí, 2 děti (6 a 10 let)\n";

        $data = (new BookingExtranetParser())->parse($raw);

        self::assertSame(2, $data->guestsAdult);
        self::assertSame(2, $data->guestsChild);
    }

    public function testParsesSingleChild(): void
    {
        $raw = "Celkový počet hostů\n2 dospělí, 1 dítě (5 let)\n";

        $data = (new BookingExtranetParser())->parse($raw);

        self::assertSame(2, $data->guestsAdult);
        self::assertSame(1, $data->guestsChild);
    }

    public function testNoPetByDefault(): void
    {
        $raw = "Jméno hosta\nTest Host\nKvětná 5 Lipov 69651\nPreferovaný jazyk\nčeština\n";

        $data = (new BookingExtranetParser())->parse($raw);

        self::assertNull($data->hasPet);
        self::assertNull($data->petsNote);
    }

    public function testDetectsPetWithoutBreed(): void
    {
        $raw = "Jméno hosta\nTest Host\nKvětná 5 Lipov 69651\nPreferovaný jazyk\nčeština\nDůležitá informace o tomto hostovi\n+ 2 psi\n";

        $data = (new BookingExtranetParser())->parse($raw);

        self::assertTrue($data->hasPet);
        self::assertSame('2 psi', $data->petsNote);
    }

    #[DataProvider('addressProvider')]
    public function testSplitsAddress(string $input, ?string $street, ?string $city, ?string $zip): void
    {
        $raw = "Jméno hosta\nTest Host\n" . $input . "\nPreferovaný jazyk\nčeština\n";
        $data = (new BookingExtranetParser())->parse($raw);

        self::assertSame($street, $data->guestStreet, 'street');
        self::assertSame($city, $data->guestCity, 'city');
        self::assertSame($zip, $data->guestZip, 'zip');
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string, 2: ?string, 3: ?string}>
     */
    public static function addressProvider(): iterable
    {
        yield 'cz bez psc' => ['sidl.5.května 872 Bechyně', 'sidl.5.května 872', 'Bechyně', null];
        yield 'cz psc na konci' => ['Dobrovského 594 Rajhrad 66461', 'Dobrovského 594', 'Rajhrad', '664 61'];
        yield 'fr velkym mestem' => ['10 rue des Eglantines HILSENHEIM 67600', '10 rue des Eglantines', 'HILSENHEIM', '676 00'];
        yield 'cz viceslovne mesto' => ['Nad Farou 918/7 Střelice u Brna 66447', 'Nad Farou 918/7', 'Střelice u Brna', '664 47'];
        yield 'cz jednoslovne' => ['Květná 5 Lipov 69651', 'Květná 5', 'Lipov', '696 51'];
    }
}
