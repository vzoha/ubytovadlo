<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Entity\Embeddable;

use App\Entity\Embeddable\BillingIdentity;
use PHPUnit\Framework\TestCase;

final class BillingIdentityTest extends TestCase
{
    public function testEmptyIdentity(): void
    {
        $identity = new BillingIdentity();

        self::assertTrue($identity->isEmpty());
        self::assertFalse($identity->isCompany());
        self::assertNull($identity->getCompanyName());
    }

    public function testBlanksNormalizeToNull(): void
    {
        $identity = new BillingIdentity(companyName: '  ', ico: ' 12345678 ', dic: '');

        self::assertNull($identity->getCompanyName());
        self::assertSame('12345678', $identity->getIco());
        self::assertNull($identity->getDic());
    }

    /** Bez názvu firmy se fakturuje fyzické osobě — samotné IČO firmu nedělá. */
    public function testIcoAloneIsNotCompany(): void
    {
        $identity = new BillingIdentity(ico: '12345678');

        self::assertFalse($identity->isCompany());
        self::assertFalse($identity->isEmpty());
    }

    public function testIsCompanyWithName(): void
    {
        self::assertTrue((new BillingIdentity('FKSP s.r.o.'))->isCompany());
    }

    public function testWithersLeaveOriginalUntouched(): void
    {
        $original = new BillingIdentity('FKSP s.r.o.', '12345678', 'CZ12345678');
        $renamed = $original->withCompanyName('Jiná s.r.o.');

        self::assertSame('FKSP s.r.o.', $original->getCompanyName());
        self::assertSame('Jiná s.r.o.', $renamed->getCompanyName());
        self::assertSame('12345678', $renamed->getIco());
    }

    public function testEquals(): void
    {
        $identity = new BillingIdentity('FKSP s.r.o.', '12345678');

        self::assertTrue($identity->equals(new BillingIdentity('FKSP s.r.o.', '12345678')));
        self::assertFalse($identity->equals($identity->withDic('CZ12345678')));
    }
}
