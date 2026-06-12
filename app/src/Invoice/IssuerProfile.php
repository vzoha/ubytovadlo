<?php

declare(strict_types=1);

namespace App\Invoice;

/**
 * Snímek dodavatele pro účely faktury. Hodnoty jdou z .env (INVOICE_ISSUER_*).
 */
final readonly class IssuerProfile
{
    public function __construct(
        public string $name,
        public string $street,
        public string $city,
        public string $zip,
        public string $country,
        public string $ico,
        public string $dic,
        public string $phone,
        public string $email,
        public string $web,
        public string $bankAccount,
        public string $bankAccountIban,
    ) {
    }
}
