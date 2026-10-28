<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Email\GuestInbox\MailAddress;
use App\Enum\EmailDirection;
use App\Repository\GuestEmailRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * E-mail z korespondence s hostem, stažený ze schránky hostů (doručené
 * i odeslané z poštovního klienta). Patří hostovi; rezervace je jen upřesnění,
 * vyplněné, když je párování jisté nebo ji majitel vybral ručně.
 */
#[ORM\Entity(repositoryClass: GuestEmailRepository::class)]
#[ORM\Table(name: 'guest_email')]
#[ORM\UniqueConstraint(name: 'uniq_guest_email_message_id', columns: ['message_id'])]
#[ORM\Index(name: 'idx_guest_email_counterpart', columns: ['counterpart_email'])]
#[ORM\Index(name: 'idx_guest_email_sent_at', columns: ['sent_at'])]
class GuestEmail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 8, enumType: EmailDirection::class)]
    private EmailDirection $direction;

    #[ORM\Column(length: 255)]
    private string $messageId;

    /** @var list<string> Message-ID zpráv, na které tahle navazuje (In-Reply-To a References). */
    #[ORM\Column(type: Types::JSON)]
    private array $threadIds;

    /** Adresa hosta: odesílatel u doručené, příjemce u odeslané. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $counterpartEmail;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $counterpartName;

    #[ORM\Column(length: 512)]
    private string $subject;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sentAt;

    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $body;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $attachmentNames;

    #[ORM\Column]
    private bool $answered = false;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Reservation $reservation = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $threadIds
     * @param list<string> $attachmentNames
     */
    public function __construct(
        EmailDirection $direction,
        string $messageId,
        MailAddress $counterpart,
        string $subject,
        \DateTimeImmutable $sentAt,
        string $body,
        array $threadIds = [],
        array $attachmentNames = [],
    ) {
        $this->direction = $direction;
        $this->messageId = $messageId;
        $this->counterpartEmail = $counterpart->email;
        $this->counterpartName = $counterpart->name !== null ? mb_substr($counterpart->name, 0, 255) : null;
        $this->subject = mb_substr($subject, 0, 512);
        $this->sentAt = $sentAt;
        $this->body = $body;
        $this->threadIds = $threadIds;
        $this->attachmentNames = $attachmentNames;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDirection(): EmailDirection
    {
        return $this->direction;
    }

    public function isInbound(): bool
    {
        return $this->direction === EmailDirection::INBOUND;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    /** @return list<string> */
    public function getThreadIds(): array
    {
        return $this->threadIds;
    }

    public function getCounterpartEmail(): ?string
    {
        return $this->counterpartEmail;
    }

    public function getCounterpartName(): ?string
    {
        return $this->counterpartName;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return list<string> */
    public function getAttachmentNames(): array
    {
        return $this->attachmentNames;
    }

    public function isAnswered(): bool
    {
        return $this->answered;
    }

    public function markAnswered(): void
    {
        $this->answered = true;
    }

    /** Odpovědět jde na e-mail od hosta se známou adresou. */
    public function isReplyable(): bool
    {
        return $this->isInbound() && $this->counterpartEmail !== null;
    }

    /**
     * Předmět odpovědi: původní s „Re:", pokud už nezačíná předponou odpovědi —
     * i českou „Odp:" nebo německou „AW:" z poštovních klientů hostů.
     */
    public function replySubject(): string
    {
        $subject = trim($this->subject);

        return preg_match('/^(re|odp|aw|sv)\s*:/iu', $subject) === 1 ? $subject : 'Re: ' . $subject;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    /**
     * Výsledek automatického párování jen doplní, co chybí — jednou přiřazeného
     * hosta (i po sloučení) ani rezervaci pozdější běh nepřepíše.
     */
    public function completeMatch(?Customer $customer, ?Reservation $reservation): void
    {
        $this->customer ??= $customer;
        $this->reservation ??= $reservation;
    }

    /** Rezervace vybraná majitelem. */
    public function assignReservation(Reservation $reservation): void
    {
        $this->reservation = $reservation;
        $this->customer = $reservation->getCustomer() ?? $this->customer;
    }

    public function moveToCustomer(Customer $customer): void
    {
        $this->customer = $customer;
    }
}
