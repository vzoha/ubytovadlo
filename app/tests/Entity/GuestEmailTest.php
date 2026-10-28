<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Email\GuestInbox\MailAddress;
use App\Entity\GuestEmail;
use App\Enum\EmailDirection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GuestEmailTest extends TestCase
{
    #[DataProvider('subjects')]
    public function testReplySubjectAddsPrefixOnlyOnce(string $subject, string $expected): void
    {
        $email = new GuestEmail(EmailDirection::INBOUND, 'a@example.com', new MailAddress(null, 'jana@example.com'), $subject, new \DateTimeImmutable(), 'Dobrý den');

        self::assertSame($expected, $email->replySubject());
    }

    /** @return iterable<string, array{string, string}> */
    public static function subjects(): iterable
    {
        yield 'bez předpony' => ['Dotaz na volno', 'Re: Dotaz na volno'];
        yield 'Re:' => ['Re: Dotaz', 'Re: Dotaz'];
        yield 'RE:' => ['RE: Dotaz', 'RE: Dotaz'];
        yield 'česká Odp:' => ['Odp: Dotaz', 'Odp: Dotaz'];
        yield 'německá AW:' => ['AW: Anfrage', 'AW: Anfrage'];
        yield 'slovo začínající re' => ['Rezervace na srpen', 'Re: Rezervace na srpen'];
    }
}
