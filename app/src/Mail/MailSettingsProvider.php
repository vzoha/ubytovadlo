<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Invoice\IssuerProfileProvider;
use App\Repository\SettingRepository;

/**
 * Sestaví nastavení odchozích e-mailů z DB (tabulka setting, klíče `mail.*`).
 * Provozovatel si je nastaví v UI (/nastaveni/mail). Odesílatel s fallbackem na
 * dodavatele faktury (jméno/e-mail), ať čerstvá instance posílá z rozumné adresy.
 */
final class MailSettingsProvider
{
    public const SENDER_NAME = 'mail.sender.name';
    public const SENDER_EMAIL = 'mail.sender.email';
    public const REPLY_TO = 'mail.reply_to';
    public const FOOTER = 'mail.footer';
    public const SHOW_LOGO = 'mail.show_logo';
    public const THEME = 'mail.theme';
    public const COLOR_PRIMARY = 'mail.color.primary';
    public const COLOR_ACCENT = 'mail.color.accent';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly IssuerProfileProvider $issuerProvider,
    ) {
    }

    public function current(): MailSettings
    {
        $issuer = $this->issuerProvider->current();

        return new MailSettings(
            senderName: $this->value(self::SENDER_NAME, $issuer->name),
            senderEmail: $this->value(self::SENDER_EMAIL, $issuer->email),
            replyTo: $this->nullableValue(self::REPLY_TO),
            footer: $this->value(self::FOOTER, ''),
            showLogo: $this->settings->getString(self::SHOW_LOGO, '1') !== '0',
            theme: MailThemes::resolve(
                $this->value(self::THEME, MailThemes::DEFAULT),
                $this->nullableValue(self::COLOR_PRIMARY),
                $this->nullableValue(self::COLOR_ACCENT),
            ),
        );
    }

    /**
     * Aktuální hodnoty pro předvyplnění formuláře.
     *
     * @return array<string, string|bool>
     */
    public function currentValues(): array
    {
        $current = $this->current();

        return [
            'senderName' => $current->senderName,
            'senderEmail' => $current->senderEmail,
            'replyTo' => $current->replyTo ?? '',
            'footer' => $current->footer,
            'showLogo' => $current->showLogo,
            'theme' => $this->value(self::THEME, MailThemes::DEFAULT),
            'colorPrimary' => $this->value(self::COLOR_PRIMARY, $current->theme->primary),
            'colorAccent' => $this->value(self::COLOR_ACCENT, $current->theme->accent),
        ];
    }

    private function value(string $key, string $fallback): string
    {
        $stored = $this->settings->getString($key);

        return $stored !== null && $stored !== '' ? $stored : $fallback;
    }

    private function nullableValue(string $key): ?string
    {
        $stored = $this->settings->getString($key);

        return $stored !== null && $stored !== '' ? $stored : null;
    }
}
