<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\MotoPress;

use App\Entity\Payment;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\PaymentSource;
use App\MotoPress\MotoPressApiException;
use App\MotoPress\MotoPressClient;
use App\MotoPress\MotoPressPaymentSyncListener;
use App\Payment\Event\PaymentSettledEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class MotoPressPaymentSyncListenerTest extends TestCase
{
    private MotoPressClient&MockObject $client;

    protected function setUp(): void
    {
        $this->client = $this->createMock(MotoPressClient::class);
    }

    public function testMarksOnlyPendingPaymentMatchingReceivedAmount(): void
    {
        // Záloha 1000 (pending, sedí částka) → completed; doplatek 5000 (pending, jiná
        // částka) i už dokončená platba se nechají být.
        $this->client->method('getBooking')->willReturn([
            'payments' => [
                ['id' => 11, 'status' => 'pending', 'amount' => '1000.00'],
                ['id' => 12, 'status' => 'pending', 'amount' => '5000.00'],
                ['id' => 13, 'status' => 'completed', 'amount' => '1000.00'],
            ],
        ]);
        $this->client->expects(self::once())
            ->method('updatePaymentStatus')
            ->with(11, 'completed');

        $this->listener(true)(new PaymentSettledEvent($this->payment('1753')));
    }

    public function testDoesNothingWhenDisabled(): void
    {
        $this->client->expects(self::never())->method('getBooking');
        $this->client->expects(self::never())->method('updatePaymentStatus');

        $this->listener(false)(new PaymentSettledEvent($this->payment('1753')));
    }

    public function testSkipsNonMotopressReservation(): void
    {
        $this->client->expects(self::never())->method('getBooking');

        $payment = $this->payment(null);
        $this->listener(true)(new PaymentSettledEvent($payment));
    }

    public function testSwallowsApiErrors(): void
    {
        // Selhání MotoPressu nesmí probublat ven — push je vedlejší efekt.
        $this->expectNotToPerformAssertions();
        $this->client->method('getBooking')->willThrowException(new MotoPressApiException('boom'));

        $this->listener(true)(new PaymentSettledEvent($this->payment('1753')));
    }

    private function listener(bool $enabled): MotoPressPaymentSyncListener
    {
        return new MotoPressPaymentSyncListener($this->client, new NullLogger(), $enabled);
    }

    private function payment(?string $bookingId): Payment
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-07-10'));
        if ($bookingId !== null) {
            $reservation->setMotopressExternalId($bookingId);
        }

        $payment = new Payment(
            PaymentSource::CS_EMAIL,
            '1000.00',
            'CZK',
            new \DateTimeImmutable('2026-06-16'),
            '<demo@csas.cz>',
        );
        $payment->setReservation($reservation);

        return $payment;
    }
}
