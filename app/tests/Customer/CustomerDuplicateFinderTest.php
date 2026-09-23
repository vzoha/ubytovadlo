<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Customer\CustomerDuplicateFinder;
use App\Customer\CustomerKey;
use App\Customer\DuplicateReason;
use App\Entity\Customer;
use App\Entity\Embeddable\GuestContact;
use App\Repository\CustomerDistinctPairRepository;
use App\Repository\CustomerRepository;
use PHPUnit\Framework\TestCase;

final class CustomerDuplicateFinderTest extends TestCase
{
    private int $nextId = 1;

    private function customer(?string $name, ?string $email = null, ?string $phone = null): Customer
    {
        $customer = new Customer($name, CustomerKey::fromContact(new GuestContact($email, $phone)));
        (new \ReflectionProperty(Customer::class, 'id'))->setValue($customer, $this->nextId++);

        return $customer;
    }

    private function finder(): CustomerDuplicateFinder
    {
        return new CustomerDuplicateFinder(
            $this->createStub(CustomerRepository::class),
            $this->createStub(CustomerDistinctPairRepository::class),
        );
    }

    public function testSameNameIsSuggestedOlderFirst(): void
    {
        $first = $this->customer('Markéta Dvořáková');
        $second = $this->customer('markéta dvořáková');

        $suggestions = $this->finder()->suggestAmong([$second, $first], []);

        self::assertCount(1, $suggestions);
        self::assertSame($first, $suggestions[0]->keep);
        self::assertSame($second, $suggestions[0]->merge);
        self::assertSame(DuplicateReason::SAME_NAME, $suggestions[0]->reason);
    }

    public function testSimilarNameAloneIsNotSuggested(): void
    {
        $suggestions = $this->finder()->suggestAmong([$this->customer('Jan Novák'), $this->customer('Petra Nováková')], []);

        self::assertSame([], $suggestions);
    }

    public function testSharedEmailWithCompatibleNameIsSuggested(): void
    {
        $suggestions = $this->finder()->suggestAmong([
            $this->customer('Jan Novák', 'novakovi@example.com'),
            $this->customer('Petra Nováková', 'novakovi@example.com'),
        ], []);

        self::assertCount(1, $suggestions);
        self::assertSame(DuplicateReason::SAME_EMAIL, $suggestions[0]->reason);
    }

    public function testSharedPhoneOfDifferentPeopleIsNotSuggested(): void
    {
        $suggestions = $this->finder()->suggestAmong([
            $this->customer('Jan Novák', phone: '+420776123456'),
            $this->customer('Marie Dvořáková', phone: '+420776123456'),
        ], []);

        self::assertSame([], $suggestions);
    }

    public function testPairMatchingOnSeveralGroundsIsSuggestedOnceByName(): void
    {
        $suggestions = $this->finder()->suggestAmong([
            $this->customer('Jan Novák', 'jan@example.com', '+420776123456'),
            $this->customer('Jan Novák', 'jan@example.com', '+420776123456'),
        ], []);

        self::assertCount(1, $suggestions);
        self::assertSame(DuplicateReason::SAME_NAME, $suggestions[0]->reason);
    }

    public function testDistinctPairIsSkipped(): void
    {
        $a = $this->customer('Jan Novák');
        $b = $this->customer('Jan Novák');

        self::assertSame([], $this->finder()->suggestAmong([$a, $b], ['1:2' => true]));
    }

    public function testNamelessCustomersAreNotPairedByName(): void
    {
        self::assertSame([], $this->finder()->suggestAmong([$this->customer(null), $this->customer('  ')], []));
    }
}
