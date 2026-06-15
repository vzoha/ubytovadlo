<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

/**
 * Instance-wide nastavení odchozích e-mailů hostům: odesílatel, adresa pro
 * odpověď, patička, zobrazení loga a barevné téma. Tajemství (SMTP přihlášení)
 * sem nepatří — to je MAILER_DSN v .env.local.
 */
final readonly class MailSettings
{
    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public ?string $replyTo,
        public string $footer,
        public bool $showLogo,
        public MailTheme $theme,
    ) {
    }
}
