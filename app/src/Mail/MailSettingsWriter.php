<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Uloží hodnoty formuláře nastavení e-mailů hostům (MailSettingsType) do Settings.
 * Sdílené samostatnou stránkou nastavení i průvodcem, aby zápis žil na jednom místě.
 */
final class MailSettingsWriter
{
    /** Pole formuláře → Setting klíč. */
    private const FIELD_KEYS = [
        'senderName' => MailSettingsProvider::SENDER_NAME,
        'senderEmail' => MailSettingsProvider::SENDER_EMAIL,
        'replyTo' => MailSettingsProvider::REPLY_TO,
        'footer' => MailSettingsProvider::FOOTER,
        'theme' => MailSettingsProvider::THEME,
        'colorPrimary' => MailSettingsProvider::COLOR_PRIMARY,
        'colorAccent' => MailSettingsProvider::COLOR_ACCENT,
    ];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param FormInterface<mixed> $form */
    public function save(FormInterface $form): void
    {
        foreach (self::FIELD_KEYS as $field => $key) {
            $this->settings->set($key, trim((string) $form->get($field)->getData()), 'Nastavení e-mailů hostům.');
        }
        $this->settings->set(
            MailSettingsProvider::SHOW_LOGO,
            $form->get('showLogo')->getData() ? '1' : '0',
            'Nastavení e-mailů hostům.',
        );
        $this->em->flush();
    }
}
