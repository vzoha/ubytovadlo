<?php

declare(strict_types=1);

namespace App\Mrz;

use App\Enum\DocumentType;

final class MrzResult
{
    public function __construct(
        public readonly string $lastName,
        public readonly string $firstName,
        public readonly \DateTimeImmutable $birthDate,
        public readonly string $sex,
        public readonly string $nationalityCode,
        public readonly DocumentType $documentType,
        public readonly string $documentNumber,
        public readonly ?\DateTimeImmutable $expiryDate = null,
        /**
         * How trustworthy this parse is, driven mainly by how many ICAO check
         * digits validated. Lets callers pick the best result across multiple
         * OCR variants / rotations instead of taking the first that parses.
         */
        public readonly int $confidence = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lastName' => $this->lastName,
            'firstName' => $this->firstName,
            'birthDate' => $this->birthDate->format('Y-m-d'),
            'sex' => $this->sex,
            'nationalityCode' => $this->nationalityCode,
            'documentType' => $this->documentType->value,
            'documentNumber' => $this->documentNumber,
            'expiryDate' => $this->expiryDate?->format('Y-m-d'),
        ];
    }
}
