<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InvoiceLinkChannel;
use App\Repository\InvoiceLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Veřejný odkaz na PDF jedné faktury (pro WhatsApp, SMS, chat). V databázi je
 * jen otisk tokenu — záloha databáze tak neobsahuje funkční odkazy. Odkaz
 * platí omezenou dobu a jde ho zrušit.
 */
#[ORM\Entity(repositoryClass: InvoiceLinkRepository::class)]
#[ORM\Table(name: 'invoice_link')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_link_token_hash', columns: ['token_hash'])]
class InvoiceLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    /** sha256 tokenu z URL (hex). */
    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastOpenedAt = null;

    #[ORM\Column(length: 16, nullable: true, enumType: InvoiceLinkChannel::class)]
    private ?InvoiceLinkChannel $channel = null;

    public function __construct(Invoice $invoice, string $tokenHash, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt)
    {
        $this->invoice = $invoice;
        $this->tokenHash = $tokenHash;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getLastOpenedAt(): ?\DateTimeImmutable
    {
        return $this->lastOpenedAt;
    }

    public function getChannel(): ?InvoiceLinkChannel
    {
        return $this->channel;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && $now < $this->expiresAt;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    public function markOpened(\DateTimeImmutable $now): void
    {
        $this->lastOpenedAt = $now;
    }

    public function markSentVia(InvoiceLinkChannel $channel): void
    {
        $this->channel = $channel;
    }
}
