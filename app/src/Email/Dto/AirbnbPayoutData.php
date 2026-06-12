<?php

declare(strict_types=1);

namespace App\Email\Dto;

/**
 * Data z Airbnb e-mailu "Poslali jsme ti výplatu …".
 * Slouží k napárování reálné výplaty (částka + datum odeslání) na rezervaci
 * podle potvrzujícího kódu a k nastavení data úhrady na faktuře hostovi.
 */
final class AirbnbPayoutData
{
    public function __construct(
        public readonly string $confirmationCode,
        public readonly float $payoutAmount,
        public readonly \DateTimeImmutable $payoutSentAt,
        public readonly ?\DateTimeImmutable $payoutExpectedAt = null,
        public readonly ?string $payoutReference = null,
        public readonly ?string $guestName = null,
    ) {
    }
}
