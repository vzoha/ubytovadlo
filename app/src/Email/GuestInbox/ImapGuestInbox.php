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
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/** Schránka hostů přes IMAP. Čte s `leaveUnread`, takže příznak Seen nemění. */
#[AsAlias(GuestInbox::class)]
final class ImapGuestInbox implements GuestInbox
{
    /** Obvyklé názvy odeslaných, když je majitel nevyplnil (Dovecot, Outlook, české webmaily). */
    private const SENT_FOLDER_CANDIDATES = ['Sent', 'INBOX.Sent', 'Sent Items', 'Sent Messages', 'Odeslane', 'INBOX.Odeslane', 'Odeslaná pošta', 'INBOX.Odeslaná pošta'];

    private ?Client $client = null;

    /** @var array<string, Folder> nalezené složky podle MailFolder — hledání stojí IMAP LIST (a STATUS) */
    private array $folders = [];

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

    public function ownAddress(): string
    {
        return mb_strtolower($this->credentials->guestMailbox()->username);
    }

    public function summaries(MailFolder $folder, \DateTimeImmutable $since): array
    {
        $query = $this->folder($folder)->query()->since($since)->leaveUnread()->setFetchBody(false)->setFetchOrderDesc();

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

    public function fetch(MailFolder $folder, string $uid): ?InboxMessage
    {
        if (!ctype_digit($uid)) {
            return null;
        }

        $query = $this->folder($folder)->query()->leaveUnread();

        try {
            $message = $query->getMessageByUid((int) $uid);
        } catch (MessageHeaderFetchingException) {
            return null;
        } catch (\Throwable $e) {
            throw new GuestInboxException(sprintf('Načtení zprávy %s ze schránky hostů selhalo: %s', $uid, $e->getMessage()), previous: $e);
        }

        return new InboxMessage(
            summary: $this->summary($message),
            text: $this->text($message),
            threadIds: self::threadIds($message),
            attachmentNames: self::attachmentNames($message),
        );
    }

    private function folder(MailFolder $folder): Folder
    {
        return $this->folders[$folder->name] ??= $this->findFolder($folder);
    }

    private function findFolder(MailFolder $folder): Folder
    {
        if ($folder === MailFolder::INBOX) {
            $path = $this->credentials->guestMailbox()->folderOrInbox();

            return $this->existingFolders([$path])[0] ?? throw self::missingFolder([$path]);
        }

        $configured = $this->credentials->guestSentFolder();
        $paths = $configured !== '' ? [$configured] : self::SENT_FOLDER_CANDIDATES;
        $found = $this->existingFolders($paths);
        // Server může mít vedle skutečných odeslaných i prázdnou výchozí `Sent` (hukot: `Odeslane`).
        foreach ($found as $candidate) {
            if (self::messageCount($candidate) > 0) {
                return $candidate;
            }
        }

        return $found[0] ?? throw self::missingFolder($paths);
    }

    /**
     * @param list<string> $paths
     *
     * @return list<Folder>
     */
    private function existingFolders(array $paths): array
    {
        $client = $this->client();
        $found = [];
        foreach ($paths as $path) {
            try {
                $folder = $client->getFolderByPath($path);
            } catch (\Throwable $e) {
                throw new GuestInboxException('Připojení ke schránce hostů selhalo: ' . $e->getMessage(), previous: $e);
            }
            if ($folder !== null) {
                $found[] = $folder;
            }
        }

        return $found;
    }

    private static function messageCount(Folder $folder): int
    {
        try {
            return (int) ($folder->status()['messages'] ?? 0);
        } catch (\Throwable $e) {
            throw new GuestInboxException(sprintf('Zjištění obsahu složky %s selhalo: %s', $folder->path, $e->getMessage()), previous: $e);
        }
    }

    /** @param list<string> $paths */
    private static function missingFolder(array $paths): GuestInboxException
    {
        return new GuestInboxException('Ve schránce hostů chybí složka ' . implode(' / ', $paths) . '.');
    }

    /** Jedno spojení na celý běh — synchronizace sahá do dvou složek a pro každou zprávu zvlášť. */
    private function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $account = $this->credentials->guestMailbox();
        if (!$account->isConfigured()) {
            throw new GuestInboxException('Schránka hostů nemá vyplněné přístupy (Nastavení → Připojení).');
        }

        try {
            return $this->client = $this->imap->connect($account);
        } catch (\Throwable $e) {
            throw new GuestInboxException('Připojení ke schránce hostů selhalo: ' . $e->getMessage(), previous: $e);
        }
    }

    private function summary(Message $message): InboxSummary
    {
        $date = $message->getDate()->first();

        return new InboxSummary(
            uid: (string) $message->getUid(),
            messageId: trim((string) $message->getMessageId()),
            from: $this->address($message->getFrom()->first()),
            to: $this->recipient($message),
            subject: self::decode((string) $message->getSubject()),
            date: $date instanceof \DateTimeInterface ? self::localTime($date) : $this->clock->now(),
            answered: $message->hasFlag('answered'),
            automated: self::isAutomated($message),
        );
    }

    /** První příjemce, který není schránka sama — majitel si občas posílá kopii nebo píše víc lidem. */
    private function recipient(Message $message): MailAddress
    {
        $own = $this->ownAddress();
        $first = null;
        foreach ($message->getTo()->all() as $address) {
            $recipient = $this->address($address);
            $first ??= $recipient;
            if ($recipient->email !== null && $recipient->email !== $own) {
                return $recipient;
            }
        }

        return $first ?? new MailAddress(null, null);
    }

    /** Hlavička Date nese pásmo odesílatele; DB ukládá čas bez pásma, tak ho sjednotíme na pásmo aplikace. */
    private static function localTime(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private function address(mixed $address): MailAddress
    {
        if (!$address instanceof Address) {
            return new MailAddress(null, null);
        }
        $name = $address->personal;
        $email = $address->mail;

        return new MailAddress(
            $name !== '' ? self::decode($name) : null,
            $email !== '' ? mb_strtolower($email) : null,
        );
    }

    private static function isAutomated(Message $message): bool
    {
        $header = $message->getHeader();
        if ($header === null) {
            return false;
        }
        $autoSubmitted = mb_strtolower(trim((string) $header->get('auto-submitted')));
        $precedence = mb_strtolower(trim((string) $header->get('precedence')));

        return $header->has('list-unsubscribe')
            || ($autoSubmitted !== '' && $autoSubmitted !== 'no')
            || \in_array($precedence, ['bulk', 'list', 'junk'], true);
    }

    /** Hosté píšou běžně prostým textem; HTML se převádí, jen když text chybí. */
    private function text(Message $message): string
    {
        $text = trim((string) $message->getTextBody());

        return $text !== '' ? $text : trim($this->htmlToText->convert((string) $message->getHTMLBody()));
    }

    /** @return list<string> */
    private static function threadIds(Message $message): array
    {
        $ids = [trim((string) $message->getInReplyTo())];
        foreach ($message->getReferences()->toArray() as $reference) {
            $ids[] = trim((string) $reference);
        }

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
    }

    /**
     * Jen skutečné přílohy — vložené obrázky (logo v podpisu, obrázky v HTML) nic neříkají.
     *
     * @return list<string>
     */
    private static function attachmentNames(Message $message): array
    {
        $names = [];
        foreach ($message->getAttachments() as $attachment) {
            $name = (string) $attachment->getName();
            $inline = str_starts_with(mb_strtolower(trim((string) $attachment->disposition)), 'inline');
            if ($name !== '' && !$inline) {
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
