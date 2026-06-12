<?php

declare(strict_types=1);

namespace App\Ares;

/**
 * Firemní údaje z ARES (jednoduché DTO pro předvyplnění fakturačního formuláře).
 */
final readonly class AresCompany
{
    public function __construct(
        public string $ico,
        public ?string $companyName,
        public ?string $street,
        public ?string $city,
        public ?string $zip,
        public ?string $country,
        public ?string $dic,
    ) {
    }

    /** @return array<string, ?string> */
    public function toArray(): array
    {
        return [
            'ico' => $this->ico,
            'companyName' => $this->companyName,
            'street' => $this->street,
            'city' => $this->city,
            'zip' => $this->zip,
            'country' => $this->country,
            'dic' => $this->dic,
        ];
    }
}
