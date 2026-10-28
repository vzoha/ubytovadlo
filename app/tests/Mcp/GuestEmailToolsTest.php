<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Customer\CustomerKey;
use App\Email\GuestInbox\GuestInbox;
use App\Email\GuestInbox\GuestInboxException;
use App\Email\GuestInbox\InboxMessage;
use App\Email\GuestInbox\InboxSender;
use App\Email\GuestInbox\InboxSummary;
use App\Entity\Customer;
use App\Entity\Embeddable\GuestContact;
use App\Mcp\McpToolException;
use App\Mcp\Tool\ListGuestEmailsTool;
use App\Mcp\Tool\ReadGuestEmailTool;
use App\Repository\CustomerRepository;
use PHPUnit\Framework\TestCase;

final class GuestEmailToolsTest extends TestCase
{
    public function testListSkipsAnsweredAndMarksKnownGuest(): void
    {
        $inbox = new FakeGuestInbox([
            self::message('11', 'jana@example.com'),
            self::message('10', 'petr@example.com', answered: true),
        ]);

        $result = $this->listTool($inbox)->call([]);

        self::assertSame(1, $result['count']);
        self::assertSame('11', $result['emails'][0]['uid']);
        self::assertSame('Jana Testová', $result['emails'][0]['knownGuest']);
        self::assertSame('Dotaz na ubytování', $result['emails'][0]['subject']);
        self::assertSame([14, 20], $inbox->lastQuery);
    }

    public function testListIncludesAnsweredOnRequestAndClampsArguments(): void
    {
        $inbox = new FakeGuestInbox([self::message('10', 'petr@example.com', answered: true)]);

        $result = $this->listTool($inbox)->call(['includeAnswered' => true, 'days' => 1000, 'limit' => 0]);

        self::assertSame(1, $result['count']);
        self::assertNull($result['emails'][0]['knownGuest']);
        self::assertSame([90, 1], $inbox->lastQuery);
    }

    public function testListReportsUnavailableMailbox(): void
    {
        $inbox = new FakeGuestInbox([], new GuestInboxException('Schránka hostů nemá vyplněné přístupy.'));

        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('nemá vyplněné přístupy');
        $this->listTool($inbox)->call([]);
    }

    public function testReadReturnsFullTextAndTruncatesLongOne(): void
    {
        $inbox = new FakeGuestInbox([self::message('11', 'jana@example.com', text: str_repeat('a', 25000))]);

        $result = (new ReadGuestEmailTool($inbox))->call(['uid' => '11']);

        self::assertSame('jana@example.com', $result['from']['email']);
        self::assertStringEndsWith('[…zkráceno]', $result['text']);
        self::assertLessThanOrEqual(20000, mb_strlen($result['text']));
    }

    public function testReadUnknownUidIsToolError(): void
    {
        $this->expectException(McpToolException::class);
        (new ReadGuestEmailTool(new FakeGuestInbox([])))->call(['uid' => '99']);
    }

    private function listTool(GuestInbox $inbox): ListGuestEmailsTool
    {
        $customers = $this->createStub(CustomerRepository::class);
        $customers->method('findByEmail')->willReturnCallback(static fn (string $email): array => $email === 'jana@example.com'
            ? [new Customer('Jana Testová', CustomerKey::fromContact(new GuestContact($email)))]
            : []);

        return new ListGuestEmailsTool($inbox, $customers);
    }

    private static function message(string $uid, string $from, bool $answered = false, string $text = 'Dotaz'): InboxMessage
    {
        return new InboxMessage(
            summary: new InboxSummary(
                uid: $uid,
                from: new InboxSender(null, $from),
                subject: 'Dotaz na ubytování',
                date: new \DateTimeImmutable('2030-06-01 10:00'),
                answered: $answered,
            ),
            messageId: '<' . $uid . '@example.com>',
            text: $text,
        );
    }
}

final class FakeGuestInbox implements GuestInbox
{
    /** @var array{int, int}|null */
    public ?array $lastQuery = null;

    /**
     * @param list<InboxMessage> $messages
     */
    public function __construct(
        private readonly array $messages,
        private readonly ?GuestInboxException $failure = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->failure === null;
    }

    public function recent(int $days, int $limit): array
    {
        $this->lastQuery = [$days, $limit];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array_map(static fn (InboxMessage $m): InboxSummary => $m->summary, array_slice($this->messages, 0, $limit));
    }

    public function find(string $uid): ?InboxMessage
    {
        foreach ($this->messages as $message) {
            if ($message->summary->uid === $uid) {
                return $message;
            }
        }

        return null;
    }
}
