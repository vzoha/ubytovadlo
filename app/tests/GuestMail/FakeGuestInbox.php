<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\GuestMail;

use App\Email\GuestInbox\GuestInbox;
use App\Email\GuestInbox\GuestInboxException;
use App\Email\GuestInbox\InboxMessage;
use App\Email\GuestInbox\InboxSummary;
use App\Email\GuestInbox\MailAddress;
use App\Email\GuestInbox\MailFolder;

/** Schránka hostů v paměti — testy synchronizace bez IMAP serveru. */
final class FakeGuestInbox implements GuestInbox
{
    public const OWN = 'info@example.com';

    /** @var array<string, list<InboxMessage>> */
    private array $folders = ['INBOX' => [], 'SENT' => []];

    /** @var array<string, GuestInboxException> */
    public array $failures = [];

    public int $fetches = 0;

    /** @var list<string> uid zpráv, jejichž stažení selže */
    public array $unreadable = [];

    /**
     * @param list<string> $threadIds
     */
    public function receive(string $uid, string $from, string $subject, string $date, string $text = 'Dobrý den', array $threadIds = [], bool $answered = false, bool $automated = false): string
    {
        return $this->add(MailFolder::INBOX, $uid, new MailAddress(null, $from), new MailAddress(null, self::OWN), $subject, $date, $text, $threadIds, $answered, $automated);
    }

    /**
     * @param list<string> $threadIds
     */
    public function send(string $uid, string $to, string $subject, string $date, array $threadIds = []): string
    {
        return $this->add(MailFolder::SENT, $uid, new MailAddress(null, self::OWN), new MailAddress(null, $to), $subject, $date, 'Dobrý den, odpovídáme', $threadIds);
    }

    /** Kopie doručené zprávy pod jiným uid a s Message-ID ve velkých písmenech — duplicitní doručení. */
    public function duplicate(string $uid, string $newUid): void
    {
        foreach ($this->folders['INBOX'] as $message) {
            if ($message->summary->uid === $uid) {
                $s = $message->summary;
                $this->folders['INBOX'][] = new InboxMessage(
                    new InboxSummary($newUid, strtoupper($s->messageId), $s->from, $s->to, $s->subject, $s->date, $s->answered, $s->automated),
                    $message->text,
                );

                return;
            }
        }
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function ownAddress(): string
    {
        return self::OWN;
    }

    public function summaries(MailFolder $folder, \DateTimeImmutable $since): array
    {
        if (isset($this->failures[$folder->name])) {
            throw $this->failures[$folder->name];
        }

        $found = array_filter($this->folders[$folder->name], static fn (InboxMessage $m): bool => $m->summary->date >= $since);
        usort($found, static fn (InboxMessage $a, InboxMessage $b): int => $b->summary->date <=> $a->summary->date);

        return array_map(static fn (InboxMessage $m): InboxSummary => $m->summary, $found);
    }

    public function fetch(MailFolder $folder, string $uid): ?InboxMessage
    {
        $this->fetches++;
        if (\in_array($uid, $this->unreadable, true)) {
            throw new GuestInboxException(sprintf('Načtení zprávy %s ze schránky hostů selhalo.', $uid));
        }
        foreach ($this->folders[$folder->name] as $message) {
            if ($message->summary->uid === $uid) {
                return $message;
            }
        }

        return null;
    }

    /**
     * @param list<string> $threadIds
     */
    private function add(MailFolder $folder, string $uid, MailAddress $from, MailAddress $to, string $subject, string $date, string $text, array $threadIds, bool $answered = false, bool $automated = false): string
    {
        $messageId = $folder->name . '-' . $uid . '@example.com';
        $this->folders[$folder->name][] = new InboxMessage(
            summary: new InboxSummary($uid, $messageId, $from, $to, $subject, new \DateTimeImmutable($date), $answered, $automated),
            text: $text,
            threadIds: $threadIds,
        );

        return $messageId;
    }
}
