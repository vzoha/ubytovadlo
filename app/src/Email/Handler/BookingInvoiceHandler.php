<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\Handler;

use App\Email\EmailMessage;
use App\Entity\EmailLog;
use App\Enum\ConnectorType;
use App\Vat\BookingInvoiceImporter;

/**
 * Booking.com měsíční vyúčtování provize → uloží jako podklad pro reverse-charge DPH.
 */
final class BookingInvoiceHandler implements EmailHandler
{
    public function __construct(
        private readonly BookingInvoiceImporter $importer,
    ) {
    }

    public function supports(EmailMessage $email): bool
    {
        return $this->importer->supports($email);
    }

    public function connectorType(): ConnectorType
    {
        return ConnectorType::BOOKING;
    }

    public function handle(EmailMessage $email, EmailLog $log): void
    {
        $this->importer->import($email, $log);
        $log->markProcessed();
    }
}
