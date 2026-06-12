<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'invoice_line')]
class InvoiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 0;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $quantity = '1';

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalPrice;

    public function __construct(string $description, string $unitPrice, string $quantity = '1', ?string $unit = null)
    {
        $this->description = $description;
        $this->unitPrice = $unitPrice;
        $this->quantity = $quantity;
        $this->unit = $unit;
        $this->totalPrice = bcmul($quantity, $unitPrice, 2);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(Invoice $invoice): self
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function getTotalPrice(): string
    {
        return $this->totalPrice;
    }
}
