<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CleaningType;
use App\Repository\CleaningRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Úklid po pobytu. Dvě veličiny:
 * - costCzk: nominální cena (vstupuje do "kolik nás pobyt stál"),
 *   počítá se i pro Barču (majitelka) — práce zadarmo neexistuje.
 * - payoutCzk: skutečná hotovost ven (0 pro Barču, 700 pro Nikolu, ...).
 *   paidAt = kdy bylo vyplaceno.
 */
#[ORM\Entity(repositoryClass: CleaningRepository::class)]
#[ORM\Table(name: 'cleaning')]
#[ORM\UniqueConstraint(name: 'uniq_reservation', columns: ['reservation_id'])]
class Cleaning
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reservation $reservation;

    #[ORM\Column(length: 32, enumType: CleaningType::class)]
    private CleaningType $type;

    #[ORM\Column(type: Types::INTEGER)]
    private int $costCzk;

    #[ORM\Column(type: Types::INTEGER)]
    private int $payoutCzk;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Reservation $reservation, CleaningType $type, int $costCzk, int $payoutCzk)
    {
        $this->reservation = $reservation;
        $this->type = $type;
        $this->costCzk = $costCzk;
        $this->payoutCzk = $payoutCzk;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getType(): CleaningType
    {
        return $this->type;
    }

    public function setType(CleaningType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getCostCzk(): int
    {
        return $this->costCzk;
    }

    public function setCostCzk(int $costCzk): self
    {
        $this->costCzk = $costCzk;

        return $this;
    }

    public function getPayoutCzk(): int
    {
        return $this->payoutCzk;
    }

    public function setPayoutCzk(int $payoutCzk): self
    {
        $this->payoutCzk = $payoutCzk;

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isPending(): bool
    {
        return $this->payoutCzk > 0 && $this->paidAt === null;
    }
}
