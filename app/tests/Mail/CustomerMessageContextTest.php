<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Customer\CustomerKey;
use App\Customer\CustomerStaysProvider;
use App\Entity\Customer;
use App\Entity\Embeddable\Address;
use App\Entity\Embeddable\GuestContact;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Mail\CustomerMessageContext;
use App\Mail\GuestLocaleResolver;
use App\Repository\ReservationRepository;
use PHPUnit\Framework\TestCase;

final class CustomerMessageContextTest extends TestCase
{
    /**
     * @param Reservation[] $stays
     */
    private function context(array $stays): CustomerMessageContext
    {
        $repository = $this->createStub(ReservationRepository::class);
        $repository->method('findAllOfCustomer')->willReturn(array_reverse($stays));

        return new CustomerMessageContext(new CustomerStaysProvider($repository), new GuestLocaleResolver());
    }

    private function customer(): Customer
    {
        $customer = new Customer('Jan Novák', CustomerKey::fromContact(new GuestContact('jan@example.com')));
        (new \ReflectionProperty(Customer::class, 'id'))->setValue($customer, 1);

        return $customer;
    }

    private function stay(string $checkIn, ?Customer $customer, string $country = 'CZ'): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable($checkIn));
        $r->setGuestAddress((new Address())->withCountry($country));
        $r->setCustomer($customer);

        return $r;
    }

    public function testReturningGuestGetsStayNumberAndGreeting(): void
    {
        $customer = $this->customer();
        $first = $this->stay('2025-06-01', $customer);
        $second = $this->stay('2026-06-01', $customer);

        $values = $this->context([$first, $second])->forReservation($second);

        self::assertSame('2', $values['stay_number']);
        self::assertSame('Jsme rádi, že se k nám zase vracíte.', $values['returning_greeting']);
    }

    public function testGreetingFollowsGuestLanguage(): void
    {
        $customer = $this->customer();
        $first = $this->stay('2025-06-01', $customer, 'DE');
        $second = $this->stay('2026-06-01', $customer, 'DE');

        self::assertSame('We are glad to welcome you back.', $this->context([$first, $second])->forReservation($second)['returning_greeting']);
    }

    public function testFirstStayHasNoGreeting(): void
    {
        $customer = $this->customer();
        $first = $this->stay('2025-06-01', $customer);
        $second = $this->stay('2026-06-01', $customer);

        $values = $this->context([$first, $second])->forReservation($first);

        self::assertSame('1', $values['stay_number']);
        self::assertSame('', $values['returning_greeting']);
    }

    public function testReservationWithoutCustomerIsEmpty(): void
    {
        $values = $this->context([])->forReservation($this->stay('2026-06-01', null));

        self::assertSame(['stay_number' => '', 'returning_greeting' => ''], $values);
    }
}
