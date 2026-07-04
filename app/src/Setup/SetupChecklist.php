<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Setup;

use App\Config\InstanceSettings;
use App\Invoice\IssuerProfileProvider;
use App\Mail\MailSettingsProvider;
use App\Repository\AccommodationProfileRepository;
use App\Repository\CredentialRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Onboarding checklist „co ještě nastavit" pro dashboard. Každou položku umí
 * vyhodnotit jako nastavenou (čte příslušný provider) a provozovatel si ji může
 * skrýt („nepoužívám"), aby nepoužívané věci netrčely navěky. Skrytí se drží
 * v Setting pod klíčem `setup.dismissed.<key>`.
 */
final class SetupChecklist
{
    private const DISMISS_PREFIX = 'setup.dismissed.';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly IssuerProfileProvider $issuer,
        private readonly MailSettingsProvider $mail,
        private readonly CredentialRepository $credentials,
        private readonly AccommodationProfileRepository $accommodation,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Všechny položky se stavem (nastaveno / skryto).
     *
     * @return list<SetupChecklistItem>
     */
    public function items(): array
    {
        $issuer = $this->issuer->current();

        return [
            $this->item(
                'instance',
                'Název a adresa aplikace',
                'Jak se aplikace jmenuje a na jaké adrese běží (odkazy v e-mailech z cronu).',
                'general_settings_edit',
                $this->filled((string) $this->settings->getString(InstanceSettings::KEY_BRAND_NAME)),
            ),
            $this->item(
                'issuer',
                'Dodavatel na faktuře',
                'Fakturační identita (jméno, adresa, IČO/DIČ) a bankovní spojení.',
                'issuer_settings_edit',
                $this->filled($issuer->name) && $this->filled($issuer->ico),
            ),
            $this->item(
                'mail',
                'Odchozí e-maily hostům',
                'Odesílatel zpráv hostům (jméno a e-mail) a vzhled.',
                'mail_settings_edit',
                $this->filled($this->mail->current()->senderEmail),
            ),
            $this->item(
                'smtp',
                'Přístup k SMTP serveru',
                'Bez něj se zprávy hostům ani notifikace neodešlou.',
                'connection_settings_edit',
                $this->credentialSet('smtp.host'),
            ),
            $this->item(
                'imap',
                'Automatizační schránka (IMAP)',
                'Čtení Booking notifikací a přeposlaných Airbnb e-mailů.',
                'connection_settings_edit',
                $this->credentialSet('imap.host'),
            ),
            $this->item(
                'motopress',
                'Napojení na web (MotoPress)',
                'Import rezervací z vlastního webu přes REST API.',
                'connection_settings_edit',
                $this->credentialSet('motopress.base_url'),
            ),
            $this->item(
                'accommodation',
                'Ubytovací zařízení (Ubyport)',
                'Údaje objektu pro hlášení ubytovaných cizinců.',
                'accommodation_profile_edit',
                $this->accommodation->getSingleton() !== null,
            ),
        ];
    }

    /**
     * Položky, které je vhodné zobrazit: nenastavené a neskryté.
     *
     * @return list<SetupChecklistItem>
     */
    public function pending(): array
    {
        return array_values(array_filter(
            $this->items(),
            static fn (SetupChecklistItem $i): bool => !$i->configured && !$i->dismissed,
        ));
    }

    /** Kolik nenastavených položek si provozovatel skryl (pro nabídku „zobrazit skryté"). */
    public function dismissedCount(): int
    {
        return count(array_filter(
            $this->items(),
            static fn (SetupChecklistItem $i): bool => !$i->configured && $i->dismissed,
        ));
    }

    /** Skryje položku (jen známý klíč). */
    public function dismiss(string $key): void
    {
        if (!$this->isKnownKey($key)) {
            return;
        }
        $this->settings->set(self::DISMISS_PREFIX . $key, '1', 'Setup checklist: skryto provozovatelem.');
        $this->em->flush();
    }

    /** Znovu zobrazí všechny skryté položky. */
    public function restore(): void
    {
        foreach ($this->items() as $item) {
            if ($item->dismissed) {
                $this->settings->set(self::DISMISS_PREFIX . $item->key, '0');
            }
        }
        $this->em->flush();
    }

    private function isKnownKey(string $key): bool
    {
        foreach ($this->items() as $item) {
            if ($item->key === $key) {
                return true;
            }
        }

        return false;
    }

    private function item(string $key, string $label, string $description, string $route, bool $configured): SetupChecklistItem
    {
        $dismissed = $this->settings->getString(self::DISMISS_PREFIX . $key) === '1';

        return new SetupChecklistItem($key, $label, $description, $route, $configured, $dismissed);
    }

    private function filled(string $value): bool
    {
        return trim($value) !== '';
    }

    /**
     * Přístupový údaj bereme za nastavený jen když ho provozovatel uložil v UI (DB),
     * ne podle env fallbacku — ten drží jen vývojové placeholdery.
     */
    private function credentialSet(string $key): bool
    {
        return $this->filled((string) $this->credentials->getDecrypted($key));
    }
}
