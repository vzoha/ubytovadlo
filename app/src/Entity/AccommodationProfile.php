<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccommodationProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton — údaje ubytovacího zařízení pro hlavičku Ubyport UNL souboru.
 * IDUB a kód přiděluje cizinecká policie při registraci ubytovatele.
 */
#[ORM\Entity(repositoryClass: AccommodationProfileRepository::class)]
#[ORM\Table(name: 'accommodation_profile')]
class AccommodationProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private string $idub;

    #[ORM\Column(length: 5)]
    private string $kod;

    #[ORM\Column(length: 255)]
    private string $nazev;

    #[ORM\Column(length: 255)]
    private string $spojeni;

    #[ORM\Column(length: 128)]
    private string $okres;

    #[ORM\Column(length: 128)]
    private string $obec;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $castObce = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $ulice = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $cp = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $co = null;

    #[ORM\Column(length: 8)]
    private string $psc;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdub(): string
    {
        return $this->idub;
    }

    public function setIdub(string $idub): self
    {
        $this->idub = $idub;

        return $this;
    }

    public function getKod(): string
    {
        return $this->kod;
    }

    public function setKod(string $kod): self
    {
        // Ubyport očekává kód zařízení uppercase (vzor: VODPO),
        // normalizujeme tady, aby form bez transformeru i přímé volání
        // produkovaly stejný výsledek.
        $this->kod = mb_strtoupper($kod, 'UTF-8');

        return $this;
    }

    public function getNazev(): string
    {
        return $this->nazev;
    }

    public function setNazev(string $nazev): self
    {
        $this->nazev = $nazev;

        return $this;
    }

    public function getSpojeni(): string
    {
        return $this->spojeni;
    }

    public function setSpojeni(string $spojeni): self
    {
        $this->spojeni = $spojeni;

        return $this;
    }

    public function getOkres(): string
    {
        return $this->okres;
    }

    public function setOkres(string $okres): self
    {
        $this->okres = $okres;

        return $this;
    }

    public function getObec(): string
    {
        return $this->obec;
    }

    public function setObec(string $obec): self
    {
        $this->obec = $obec;

        return $this;
    }

    public function getCastObce(): ?string
    {
        return $this->castObce;
    }

    public function setCastObce(?string $castObce): self
    {
        $this->castObce = $castObce;

        return $this;
    }

    public function getUlice(): ?string
    {
        return $this->ulice;
    }

    public function setUlice(?string $ulice): self
    {
        $this->ulice = $ulice;

        return $this;
    }

    public function getCp(): ?string
    {
        return $this->cp;
    }

    public function setCp(?string $cp): self
    {
        $this->cp = $cp;

        return $this;
    }

    public function getCo(): ?string
    {
        return $this->co;
    }

    public function setCo(?string $co): self
    {
        $this->co = $co;

        return $this;
    }

    public function getPsc(): string
    {
        return $this->psc;
    }

    public function setPsc(string $psc): self
    {
        $this->psc = $psc;

        return $this;
    }
}
