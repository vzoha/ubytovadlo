<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\MotoPress;

use App\MotoPress\MotoPressBookingClassifier;
use App\MotoPress\MotoPressBookingKind;
use PHPUnit\Framework\TestCase;

final class MotoPressBookingClassifierTest extends TestCase
{
    private MotoPressBookingClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new MotoPressBookingClassifier();
    }

    public function testWebBookingFromFixture(): void
    {
        $result = $this->classifier->classify($this->loadFixture('booking-confirmed.json'));

        self::assertSame(MotoPressBookingKind::WEB, $result->kind);
        self::assertNull($result->airbnbConfirmationCode);
    }

    public function testAirbnbIcalImportExtractsConfirmationCode(): void
    {
        $result = $this->classifier->classify($this->loadFixture('ical-airbnb.json'));

        self::assertSame(MotoPressBookingKind::IMPORTED_AIRBNB, $result->kind);
        self::assertSame('HMABCD12EF', $result->airbnbConfirmationCode);
    }

    public function testBookingIcalImport(): void
    {
        $result = $this->classifier->classify($this->loadFixture('ical-booking.json'));

        self::assertSame(MotoPressBookingKind::IMPORTED_BOOKING, $result->kind);
        self::assertNull($result->airbnbConfirmationCode);
    }

    public function testUnknownProdidFallsBack(): void
    {
        $result = $this->classifier->classify([
            'imported' => true,
            'ical_prodid' => '-//Some Other Provider//EN',
        ]);

        self::assertSame(MotoPressBookingKind::IMPORTED_UNKNOWN, $result->kind);
    }

    public function testAirbnbWithoutDescriptionReturnsNullCode(): void
    {
        $result = $this->classifier->classify([
            'imported' => true,
            'ical_prodid' => '-//Airbnb Inc//Hosting Calendar 1.0//EN',
            'ical_description' => '',
        ]);

        self::assertSame(MotoPressBookingKind::IMPORTED_AIRBNB, $result->kind);
        self::assertNull($result->airbnbConfirmationCode);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $path = __DIR__ . '/../Fixtures/MotoPress/' . $name;
        $content = (string) file_get_contents($path);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
