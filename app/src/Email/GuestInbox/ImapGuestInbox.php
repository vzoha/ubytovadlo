<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\GuestInbox;

use App\Credential\CredentialProvider;
use App\Email\HtmlToTextConverter;
use App\Email\ImapClientFactory;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/** Schránka hostů přes IMAP. Čte s `leaveUnread`, takže příznak Seen nemění. */
#[AsAlias(GuestInbox::class)]
final class ImapGuestInbox implements GuestInbox
{
    public function __construct(
        private readonly CredentialProvider $credentials,
        private readonly ImapClientFactory $imap,
        private readonly HtmlToTextConverter $htmlToText,
        private readonly ClockInterface $clock,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->credentials->guestMailbox()->isConfigured();
    }

    public function recent(int $days, int $limit): array
    {
        $since = $this->clock->now()->modify(sprintf('-%d days', $days))->setTime(0, 0);
        $query = $this->folder()->query()->since($since)->leaveUnread()->setFetchBody(false)->setFetchOrderDesc()->limit($limit);

        try {
            $messages = $query->get();
        } catch (\Throwable $e) {
            throw new GuestInboxException('Načtení zpráv ze schránky hostů selhalo: ' . $e->getMessage(), previous: $e);
        }

        $result = [];
        foreach ($messages as $message) {
            $result[] = $this->summary($message);
        }

        return $result;
    }

    public function find(string $uid): ?InboxMessage
    {
        if (!ctype_digit($uid)) {
            return null;
        }

        $query = $this->folder()->query()->leaveUnread();

        try {
            $message = $query->getMessageByUid((int) $uid);
        } catch (MessageHeaderFetchingException) {
            return null;
        } catch (\Throwable $e) {
            throw new GuestInboxException(sprintf('Načtení zprávy %s ze schránky hostů selhalo: %s', $uid, $e->getMessage()), previous: $e);
        }

        return $this->toInboxMessage($message);
    }

    private function folder(): Folder
    {
        $account = $this->credentials->guestMailbox();
        if (!$account->isConfigured()) {
            throw new GuestInboxException('Schránka hostů nemá vyplněné přístupy (Nastavení → Připojení).');
        }

        try {
            $folder = $this->imap->connect($account)->getFolderByPath($account->folderOrInbox());
        } catch (\Throwable $e) {
            throw new GuestInboxException('Připojení ke schránce hostů selhalo: ' . $e->getMessage(), previous: $e);
        }
        if ($folder === null) {
            throw new GuestInboxException('Ve schránce hostů chybí složka ' . $account->folderOrInbox() . '.');
        }

        return $folder;
    }

    private function summary(Message $message): InboxSummary
    {
        $date = $message->getDate()->first();

        return new InboxSummary(
            uid: (string) $message->getUid(),
            from: $this->sender($message),
            subject: self::decode((string) $message->getSubject()),
            date: $date instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($date) : $this->clock->now(),
            answered: $message->hasFlag('answered'),
        );
    }

    private function toInboxMessage(Message $message): InboxMessage
    {
        $inReplyTo = trim((string) $message->getInReplyTo());

        return new InboxMessage(
            summary: $this->summary($message),
            messageId: trim((string) $message->getMessageId()),
            text: $this->text($message),
            inReplyTo: $inReplyTo !== '' ? $inReplyTo : null,
            attachmentNames: self::attachmentNames($message),
        );
    }

    private function sender(Message $message): InboxSender
    {
        $from = $message->getFrom()->first();
        if ($from === null) {
            return new InboxSender(null, null);
        }
        $name = (string) $from->personal;
        $email = (string) $from->mail;

        return new InboxSender(
            $name !== '' ? self::decode($name) : null,
            $email !== '' ? mb_strtolower($email) : null,
        );
    }

    /** Hosté píšou běžně prostým textem; HTML se převádí, jen když text chybí. */
    private function text(Message $message): string
    {
        $text = trim((string) $message->getTextBody());

        return $text !== '' ? $text : trim($this->htmlToText->convert((string) $message->getHTMLBody()));
    }

    /** @return list<string> */
    private static function attachmentNames(Message $message): array
    {
        $names = [];
        foreach ($message->getAttachments() as $attachment) {
            $name = (string) $attachment->getName();
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** Hlavičky v MIME kódování (=?UTF-8?…?=) knihovna někdy nechá nedekódované. */
    private static function decode(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded !== false ? $decoded : $value;
    }
}
