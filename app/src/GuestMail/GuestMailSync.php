<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\GuestMail;

use App\Email\GuestInbox\GuestInbox;
use App\Email\GuestInbox\GuestInboxException;
use App\Email\GuestInbox\InboxSummary;
use App\Email\GuestInbox\MailFolder;
use App\Entity\GuestEmail;
use App\Enum\EmailDirection;
use App\Repository\GuestEmailRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Stáhne novou korespondenci ze schránky hostů (doručené i odeslané) do DB
 * a spáruje ji s hosty a rezervacemi. Idempotentní: zprávu pozná podle
 * Message-ID, takže překryv časových oken nevadí.
 */
class GuestMailSync
{
    /** Poslední stažení doručených — zároveň „naposledy staženo" v UI. */
    public const SYNCED_AT_KEY = 'guest_mail.synced_at';

    /** Odeslané mají vlastní okno: když jejich složka chybí nebo se opraví, doplní se zpětně. */
    private const SENT_SYNCED_AT_KEY = 'guest_mail.sent_synced_at';

    /** Při prvním běhu se stáhne tolik dní zpět. */
    private const INITIAL_DAYS = 90;

    /** Překryv s minulým během — IMAP SINCE má přesnost na dny a zprávy se doručují se zpožděním. */
    private const OVERLAP_DAYS = 2;

    /** Jak dlouho zpětně se zkouší spárovat e-maily, ke kterým host nebo rezervace přibyli později. */
    private const REMATCH_DAYS = 30;

    /** Dlouhé citace starší korespondence k ničemu nejsou a jen plní DB. */
    private const MAX_BODY = 20000;

    public function __construct(
        private readonly GuestInbox $inbox,
        private readonly GuestEmailRepository $emails,
        private readonly GuestEmailMatcher $matcher,
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->inbox->isConfigured();
    }

    /**
     * @throws GuestInboxException když nejde přečíst doručené (bez odeslaných se běh obejde)
     */
    public function sync(): GuestMailSyncResult
    {
        $now = $this->clock->now();
        $result = new GuestMailSyncResult();

        $inbound = $this->importFolder(MailFolder::INBOX, $now, $result);
        $outbound = [];
        try {
            $outbound = $this->importFolder(MailFolder::SENT, $now, $result);
        } catch (GuestInboxException $e) {
            $result->warnings[] = $e->getMessage();
        }
        $result->inbound = \count($inbound);
        $result->outbound = \count($outbound);

        $imported = array_merge($inbound, $outbound);
        foreach ($this->emails->findIncompletelyMatched($now->modify(sprintf('-%d days', self::REMATCH_DAYS))) as $email) {
            if (!\in_array($email, $imported, true)) {
                $this->matcher->match($email);
            }
        }
        $this->em->flush();

        return $result;
    }

    private static function syncedAtKey(MailFolder $folder): string
    {
        return $folder === MailFolder::INBOX ? self::SYNCED_AT_KEY : self::SENT_SYNCED_AT_KEY;
    }

    private function since(MailFolder $folder, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $last = $this->settings->getString(self::syncedAtKey($folder));
        $since = $last !== null && $last !== ''
            ? (new \DateTimeImmutable($last))->modify(sprintf('-%d days', self::OVERLAP_DAYS))
            : $now->modify(sprintf('-%d days', self::INITIAL_DAYS));

        return $since->setTime(0, 0);
    }

    /** @return list<GuestEmail> nově uložené */
    private function importFolder(MailFolder $folder, \DateTimeImmutable $now, GuestMailSyncResult $result): array
    {
        $direction = $folder === MailFolder::INBOX ? EmailDirection::INBOUND : EmailDirection::OUTBOUND;
        $candidates = array_filter(
            $this->inbox->summaries($folder, $this->since($folder, $now)),
            fn (InboxSummary $s): bool => $this->isGuestCorrespondence($s, $direction),
        );
        // Od nejstarší, ať odpověď najde ve vlákně zprávu, na kterou reaguje.
        $candidates = array_reverse(array_values($candidates));

        // Klíč bez ohledu na velikost písmen — tak porovnává i unikátní index v DB.
        $known = [];
        foreach ($this->emails->findByMessageIds(array_map(static fn (InboxSummary $s): string => $s->messageId, $candidates)) as $email) {
            $known[mb_strtolower($email->getMessageId())] = $email;
        }

        $imported = [];
        foreach ($candidates as $summary) {
            $key = mb_strtolower($summary->messageId);
            if (isset($known[$key])) {
                if ($summary->answered) {
                    $known[$key]->markAnswered();
                }
                continue;
            }
            try {
                $email = $this->import($folder, $direction, $summary);
            } catch (GuestInboxException $e) {
                // Jedna nečitelná zpráva nesmí zastavit stahování ostatních.
                $result->warnings[] = $e->getMessage();
                continue;
            }
            if ($email !== null) {
                // Stejná zpráva může ve složce ležet dvakrát (duplicitní doručení).
                $known[$key] = $email;
                $imported[] = $email;
            }
        }
        $this->settings->set(self::syncedAtKey($folder), $now->format(\DateTimeInterface::ATOM), 'Poslední stažení korespondence s hosty.');
        $this->em->flush();

        return $imported;
    }

    private function isGuestCorrespondence(InboxSummary $summary, EmailDirection $direction): bool
    {
        $counterpart = $direction === EmailDirection::INBOUND ? $summary->from : $summary->to;

        return $summary->messageId !== ''
            && !$summary->automated
            && $counterpart->email !== null
            && $counterpart->email !== $this->inbox->ownAddress();
    }

    private function import(MailFolder $folder, EmailDirection $direction, InboxSummary $summary): ?GuestEmail
    {
        $message = $this->inbox->fetch($folder, $summary->uid);
        if ($message === null) {
            return null;
        }

        $email = new GuestEmail(
            direction: $direction,
            messageId: $summary->messageId,
            counterpart: $direction === EmailDirection::INBOUND ? $summary->from : $summary->to,
            subject: $summary->subject,
            sentAt: $summary->date,
            body: mb_strimwidth($message->text, 0, self::MAX_BODY, "\n[…zkráceno]"),
            threadIds: $message->threadIds,
            attachmentNames: $message->attachmentNames,
        );
        if ($summary->answered) {
            $email->markAnswered();
        }
        if ($direction === EmailDirection::OUTBOUND) {
            foreach ($this->emails->findByMessageIds($message->threadIds) as $earlier) {
                if ($earlier->isInbound()) {
                    $earlier->markAnswered();
                }
            }
        }

        $this->em->persist($email);
        $this->matcher->match($email);
        // Hned do DB — další zpráva ve stejném běhu ji může hledat ve vlákně.
        $this->em->flush();

        return $email;
    }
}
