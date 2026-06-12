<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NationalityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Číselník státního občanství pro Ubyport (sources/ubyport/staty_kod.csv).
 * Kód = 3 písmena (např. SVK, DEU, USA). Plněno data fixture.
 */
#[ORM\Entity(repositoryClass: NationalityRepository::class)]
#[ORM\Table(name: 'nationality')]
class Nationality
{
    #[ORM\Id]
    #[ORM\Column(length: 3)]
    private string $code;

    #[ORM\Column(length: 128)]
    private string $nameCs;

    #[ORM\Column(length: 128)]
    private string $nameEn;

    public function __construct(string $code, string $nameCs, string $nameEn)
    {
        $this->code = $code;
        $this->nameCs = $nameCs;
        $this->nameEn = $nameEn;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getNameCs(): string
    {
        return $this->nameCs;
    }

    public function setNameCs(string $nameCs): self
    {
        $this->nameCs = $nameCs;

        return $this;
    }

    public function getNameEn(): string
    {
        return $this->nameEn;
    }

    public function setNameEn(string $nameEn): self
    {
        $this->nameEn = $nameEn;

        return $this;
    }
}
