<?php

declare(strict_types=1);

namespace App\Vat;

final class BookingInvoiceData
{
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly \DateTimeImmutable $issuedAt,
        public readonly \DateTimeImmutable $periodFrom,
        public readonly \DateTimeImmutable $periodTo,
        public readonly string $currency,
        public readonly string $roomSales,
        public readonly string $commission,
        public readonly string $paymentFee,
        public readonly string $totalPayable,
        public readonly ?string $bookingExchangeRate,
    ) {
    }
}
