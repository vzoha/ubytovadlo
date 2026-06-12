<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ElectricityReadingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Odečet elektroměru (absolutní stav VT/NT v kWh).
 * Mezi dvěma sousedními odečty ElectricityAllocator rozdělí spotřebu
 * mezi rezervace váhou nights × sezónní faktor.
 */
#[ORM\Entity(repositoryClass: ElectricityReadingRepository::class)]
#[ORM\Table(name: 'electricity_reading')]
#[ORM\UniqueConstraint(name: 'uniq_read_at', columns: ['read_at'])]
class ElectricityReading
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $readAt;

    #[ORM\Column(type: Types::INTEGER)]
    private int $vtMeter;

    #[ORM\Column(type: Types::INTEGER)]
    private int $ntMeter;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(\DateTimeImmutable $readAt, int $vtMeter, int $ntMeter)
    {
        $this->readAt = $readAt;
        $this->vtMeter = $vtMeter;
        $this->ntMeter = $ntMeter;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReadAt(): \DateTimeImmutable
    {
        return $this->readAt;
    }

    public function getVtMeter(): int
    {
        return $this->vtMeter;
    }

    public function setVtMeter(int $vtMeter): self
    {
        $this->vtMeter = $vtMeter;

        return $this;
    }

    public function getNtMeter(): int
    {
        return $this->ntMeter;
    }

    public function setNtMeter(int $ntMeter): self
    {
        $this->ntMeter = $ntMeter;

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
}
