<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Email\GuestInbox\MailAddress;
use App\Entity\GuestEmail;
use App\Enum\EmailDirection;
use App\Mcp\McpToolException;
use App\Mcp\Tool\ListGuestEmailsTool;
use App\Mcp\Tool\ReadGuestEmailTool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GuestEmailToolsTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $em->getConnection()->executeStatement('DELETE FROM guest_email');
    }

    public function testListReturnsRecentUnansweredInbound(): void
    {
        $this->email('1', EmailDirection::INBOUND, 'Volno v srpnu?', 'now -1 day');
        $this->email('2', EmailDirection::INBOUND, 'Zodpovězený', 'now -1 day', answered: true);
        $this->email('3', EmailDirection::OUTBOUND, 'Re: Volno', 'now -1 day');
        $this->email('4', EmailDirection::INBOUND, 'Starý', 'now -30 days');

        $result = $this->listTool()->call([]);

        self::assertSame(1, $result['count']);
        self::assertSame('Volno v srpnu?', $result['emails'][0]['subject']);
        self::assertFalse($result['emails'][0]['answered']);
    }

    public function testListIncludesAnsweredAndOlderOnRequest(): void
    {
        $this->email('2', EmailDirection::INBOUND, 'Zodpovězený', 'now -1 day', answered: true);
        $this->email('4', EmailDirection::INBOUND, 'Starý', 'now -30 days');

        $result = $this->listTool()->call(['includeAnswered' => true, 'days' => 60]);

        self::assertSame(2, $result['count']);
    }

    public function testReadReturnsTextAndConversationWithSameGuest(): void
    {
        $question = $this->email('1', EmailDirection::INBOUND, 'Volno v srpnu?', 'now -2 days', 'Máte volno 12.–19. 8.?');
        $this->email('3', EmailDirection::OUTBOUND, 'Re: Volno', 'now -1 day');
        $this->email('5', EmailDirection::INBOUND, 'Cizí', 'now -1 day', from: 'petr@example.com');

        $result = $this->readTool()->call(['id' => $question->getId()]);

        self::assertSame('Máte volno 12.–19. 8.?', $result['text']);
        self::assertCount(1, $result['conversation']);
        self::assertSame('hostovi', $result['conversation'][0]['direction']);
    }

    public function testReadUnknownIdIsToolError(): void
    {
        $this->expectException(McpToolException::class);
        $this->readTool()->call(['id' => 999999]);
    }

    private function email(string $id, EmailDirection $direction, string $subject, string $sentAt, string $body = 'Dobrý den', bool $answered = false, string $from = 'jana@example.com'): GuestEmail
    {
        $email = new GuestEmail($direction, $id . '@example.com', new MailAddress(null, $from), $subject, new \DateTimeImmutable($sentAt), $body);
        if ($answered) {
            $email->markAnswered();
        }
        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }

    private function listTool(): ListGuestEmailsTool
    {
        return static::getContainer()->get(ListGuestEmailsTool::class);
    }

    private function readTool(): ReadGuestEmailTool
    {
        return static::getContainer()->get(ReadGuestEmailTool::class);
    }
}
