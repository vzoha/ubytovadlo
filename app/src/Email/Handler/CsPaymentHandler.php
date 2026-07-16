<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\Handler;

use App\Email\CsPaymentParser;
use App\Email\EmailMessage;
use App\Entity\EmailLog;
use App\Enum\ConnectorType;
use App\Payment\PaymentProcessor;

/**
 * ČS notifikace „Přišla platba" → napáruje platbu na rezervaci podle variabilního
 * symbolu (kód rezervace) a promítne úhradu.
 */
final class CsPaymentHandler implements EmailHandler
{
    public function __construct(
        private readonly CsPaymentParser $parser,
        private readonly PaymentProcessor $paymentProcessor,
    ) {
    }

    public function supports(EmailMessage $email): bool
    {
        return $this->parser->supports($email);
    }

    public function connectorType(): ConnectorType
    {
        return ConnectorType::BANK_CS;
    }

    public function handle(EmailMessage $email, EmailLog $log): void
    {
        $result = $this->paymentProcessor->process($this->parser->parse($email), $email);
        if ($result->reservation !== null) {
            $log->markProcessed($result->reservation);
        } else {
            $log->markIgnored($result->ignoredReason);
        }
    }
}
