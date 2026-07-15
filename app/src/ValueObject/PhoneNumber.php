<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\ValueObject;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber as LibPhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Telefonní číslo v kanonickém tvaru E.164 (+420776123456).
 *
 * Ukládá se jako E.164 string; formátovací metody dávají tvary pro UI a deep linky
 * (tel:, sms:, wa.me). Vstup se parsuje s výchozím regionem, aby prošla i lokální
 * čísla bez předvolby ("776 123 456" → "+420776123456").
 */
final readonly class PhoneNumber implements \Stringable
{
    private function __construct(
        private LibPhoneNumber $number,
    ) {
    }

    /**
     * @param non-empty-string $defaultRegion ISO 3166-1 alpha-2 region pro čísla bez předvolby
     *
     * @throws \InvalidArgumentException když číslo nejde naparsovat nebo není platné
     */
    public static function fromString(string $raw, string $defaultRegion = 'CZ'): self
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($raw, $defaultRegion);
        } catch (NumberParseException $e) {
            throw new \InvalidArgumentException(sprintf('Neplatné telefonní číslo: "%s".', $raw), 0, $e);
        }

        if (!$util->isValidNumber($parsed)) {
            throw new \InvalidArgumentException(sprintf('Neplatné telefonní číslo: "%s".', $raw));
        }

        return new self($parsed);
    }

    /**
     * Volná varianta pro nedůvěryhodná data (OTA importy) — vrátí null místo výjimky.
     *
     * @param non-empty-string $defaultRegion
     */
    public static function tryFromString(?string $raw, string $defaultRegion = 'CZ'): ?self
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            return self::fromString($raw, $defaultRegion);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** Kanonický tvar pro ukládání a tel:/sms: odkazy — "+420776123456". */
    public function e164(): string
    {
        return PhoneNumberUtil::getInstance()->format($this->number, PhoneNumberFormat::E164);
    }

    /** Národní tvar pro zobrazení — "776 123 456". */
    public function national(): string
    {
        return PhoneNumberUtil::getInstance()->format($this->number, PhoneNumberFormat::NATIONAL);
    }

    /** Mezinárodní tvar pro zobrazení — "+420 776 123 456". */
    public function international(): string
    {
        return PhoneNumberUtil::getInstance()->format($this->number, PhoneNumberFormat::INTERNATIONAL);
    }

    /** Jen číslice bez '+' pro wa.me / WhatsApp deep link — "420776123456". */
    public function whatsapp(): string
    {
        return ltrim($this->e164(), '+');
    }

    public function __toString(): string
    {
        return $this->e164();
    }
}
