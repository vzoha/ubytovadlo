<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MessageKind;
use App\Enum\SendMode;
use App\Enum\TimingAnchor;
use App\Repository\MessageTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uživatelsky editovatelná šablona e-mailu hostovi (předmět + tělo v Markdownu
 * s proměnnými), její režim odesílání a časování na ose rezervace. V DB žije jen
 * override — výchozí texty i časování jsou v kódu (MessageTemplateDefaults). Jeden
 * řádek na druh zprávy.
 */
#[ORM\Entity(repositoryClass: MessageTemplateRepository::class)]
#[ORM\Table(name: 'message_template')]
class MessageTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true, enumType: MessageKind::class)]
    private MessageKind $kind;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $bodyMarkdown;

    #[ORM\Column(length: 8, enumType: SendMode::class)]
    private SendMode $mode = SendMode::OFF;

    /** Kotva na ose, vůči které se počítá čas odeslání (jen u plánovaných zpráv). */
    #[ORM\Column(length: 16, nullable: true, enumType: TimingAnchor::class)]
    private ?TimingAnchor $anchor = null;

    /** Posun vůči kotvě ve dnech; záporné = před, kladné = po, 0 = v den kotvy. */
    #[ORM\Column(nullable: true)]
    private ?int $offsetDays = null;

    /** Hodina odeslání „HH:MM"; null = zdědí čas kotvy (typicky přesný čas objednávky). */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $sendAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(MessageKind $kind, string $subject, string $bodyMarkdown)
    {
        $this->kind = $kind;
        $this->subject = $subject;
        $this->bodyMarkdown = $bodyMarkdown;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): MessageKind
    {
        return $this->kind;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;
        $this->touch();

        return $this;
    }

    public function getBodyMarkdown(): string
    {
        return $this->bodyMarkdown;
    }

    public function setBodyMarkdown(string $bodyMarkdown): self
    {
        $this->bodyMarkdown = $bodyMarkdown;
        $this->touch();

        return $this;
    }

    public function getMode(): SendMode
    {
        return $this->mode;
    }

    public function setMode(SendMode $mode): self
    {
        $this->mode = $mode;
        $this->touch();

        return $this;
    }

    public function getAnchor(): ?TimingAnchor
    {
        return $this->anchor;
    }

    public function setAnchor(?TimingAnchor $anchor): self
    {
        $this->anchor = $anchor;
        $this->touch();

        return $this;
    }

    public function getOffsetDays(): ?int
    {
        return $this->offsetDays;
    }

    public function setOffsetDays(?int $offsetDays): self
    {
        $this->offsetDays = $offsetDays;
        $this->touch();

        return $this;
    }

    public function getSendAt(): ?string
    {
        return $this->sendAt;
    }

    public function setSendAt(?string $sendAt): self
    {
        $this->sendAt = $sendAt !== null && $sendAt !== '' ? $sendAt : null;
        $this->touch();

        return $this;
    }

    /** Nastaví celé časování zprávy najednou (kotva + posun + hodina). */
    public function setTiming(TimingAnchor $anchor, int $offsetDays, ?string $sendAt): self
    {
        $this->anchor = $anchor;
        $this->offsetDays = $offsetDays;

        return $this->setSendAt($sendAt);
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Lidský popis časování pro UI, např. „3 dny před příjezdem v 9:00". */
    public function timingSummary(): ?string
    {
        if ($this->anchor === null) {
            return null;
        }

        $offset = $this->offsetDays ?? 0;
        $days = abs($offset);
        $when = match (true) {
            $offset < 0 => sprintf('%s před %s', self::days($days), $this->anchor->before()),
            $offset > 0 => sprintf('%s po %s', self::days($days), $this->anchor->after()),
            default => sprintf('v den — %s', $this->anchor->label()),
        };

        if ($this->sendAt !== null) {
            $when .= ' v ' . ltrim($this->sendAt, '0');
        }

        return $when;
    }

    private static function days(int $n): string
    {
        return match ($n) {
            1 => '1 den',
            2, 3, 4 => $n . ' dny',
            default => $n . ' dní',
        };
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
