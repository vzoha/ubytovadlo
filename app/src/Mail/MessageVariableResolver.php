<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Entity\Reservation;
use App\Formatting\Money;
use App\Invoice\BalanceCalculator;
use App\Invoice\DepositPayment;
use App\Invoice\DepositPaymentBuilder;
use App\Repository\AccommodationProfileRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Bezpečné dosazení proměnných do textu šablony. Záměrně NEspouští Twig nad
 * uživatelsky editovaným textem (injection) — jen nahradí whitelist placeholderů
 * {{ nazev }} hodnotami odvozenými z rezervace, profilu ubytování a doplatku.
 * Neznámé placeholdery zůstanou v textu (provozovatel uvidí svůj překlep).
 */
final class MessageVariableResolver
{
    /** @var array<string, string> název proměnné => popis pro paletu v UI */
    private const VARIABLES = [
        'guest_name' => 'Celé jméno hosta',
        'guest_first_name' => 'Křestní jméno hosta',
        'guest_first_name_vocative' => 'Křestní jméno hosta v 5. pádu (oslovení)',
        'guest_last_name' => 'Příjmení hosta',
        'guest_last_name_vocative' => 'Příjmení hosta v 5. pádu (oslovení)',
        'check_in' => 'Datum příjezdu',
        'check_in_time' => 'Čas příjezdu',
        'check_out' => 'Datum odjezdu',
        'check_out_time' => 'Čas odjezdu',
        'nights' => 'Počet nocí',
        'guests_total' => 'Počet hostů celkem',
        'guests_adult' => 'Počet dospělých',
        'guests_child' => 'Počet dětí',
        'price_total' => 'Celková cena',
        'balance_due' => 'Zbývající doplatek',
        'channel' => 'Zdroj rezervace',
        'accommodation_name' => 'Název ubytování',
        'accommodation_address' => 'Adresa ubytování',
        'checkin_url' => 'Odkaz na online check-in',
        'invoice_number' => 'Číslo faktury',
        'deposit_amount' => 'Výše zálohy k zaplacení',
        'deposit_due' => 'Splatnost zálohy (datum)',
        'bank_account' => 'Číslo účtu pro platbu',
        'variable_symbol' => 'Variabilní symbol platby',
        'deposit_qr' => 'QR kód pro platbu zálohy (obrázek)',
    ];

    private const DEFAULT_CHECK_IN_TIME = '15:00';
    private const DEFAULT_CHECK_OUT_TIME = '10:00';

    public function __construct(
        private readonly AccommodationProfileRepository $profiles,
        private readonly BalanceCalculator $balance,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly GuestVocative $vocative,
        private readonly DepositPaymentBuilder $deposits,
    ) {
    }

    /** Proměnné, které nedávají smysl v prostém textu (SMS/WhatsApp) — obrázky apod. */
    private const NON_TEXT_VARIABLES = ['deposit_qr'];

    /** @return array<string, string> název => popis */
    public static function variables(): array
    {
        return self::VARIABLES;
    }

    /**
     * Podmnožina proměnných vhodná pro prostý text (SMS/WhatsApp) — bez obrázků.
     *
     * @return array<string, string> název => popis
     */
    public static function plainTextVariables(): array
    {
        return array_diff_key(self::VARIABLES, array_flip(self::NON_TEXT_VARIABLES));
    }

    /**
     * Nahradí všechny {{ nazev }} v textu hodnotami pro danou rezervaci.
     *
     * @param array<string, string> $context dodatečné hodnoty (např. invoice_number)
     */
    public function render(string $text, Reservation $reservation, array $context = []): string
    {
        $values = $this->values($reservation, $context);

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            static fn (array $m): string => $values[$m[1]] ?? $m[0],
            $text,
        );
    }

    /**
     * @param array<string, string> $context
     *
     * @return array<string, string>
     */
    private function values(Reservation $reservation, array $context): array
    {
        $profile = $this->profiles->getSingleton();
        $balance = $this->balance->forReservation($reservation);
        $deposit = $this->deposits->forReservation($reservation);

        $values = [
            'guest_name' => $reservation->getGuestName() ?? '',
            'guest_first_name' => $this->firstName($reservation->getGuestName()),
            'guest_first_name_vocative' => $this->vocative->firstName($reservation->getGuestName()),
            'guest_last_name' => $this->lastName($reservation->getGuestName()),
            'guest_last_name_vocative' => $this->vocative->lastName($reservation->getGuestName()),
            'check_in' => $reservation->getCheckIn()->format('j. n. Y'),
            'check_in_time' => $this->time($reservation->getCheckInTime(), self::DEFAULT_CHECK_IN_TIME),
            'check_out' => $reservation->getCheckOut()?->format('j. n. Y') ?? '',
            'check_out_time' => $this->time($reservation->getCheckOutTime(), self::DEFAULT_CHECK_OUT_TIME),
            'nights' => (string) $this->nights($reservation),
            'guests_total' => (string) $reservation->getGuestsTotal(),
            'guests_adult' => (string) $reservation->getGuestsAdult(),
            'guests_child' => (string) $reservation->getGuestsChild(),
            'price_total' => $this->money($reservation->getPriceTotal(), $reservation->getPriceCurrency()),
            'balance_due' => $balance !== null && $balance->remaining > 0.0
                ? $this->money(Money::normalize($balance->remaining), 'CZK')
                : '',
            'channel' => $reservation->getChannel()->label(),
            'accommodation_name' => $profile?->getNazev() ?? '',
            'accommodation_address' => $this->address($profile),
            'checkin_url' => $this->checkinUrl($reservation),
            'invoice_number' => '',
            'deposit_amount' => $deposit !== null ? $this->depositAmount($deposit->amount) : '',
            'deposit_due' => $deposit?->dueDate->format('j. n. Y') ?? '',
            'bank_account' => $deposit !== null ? $deposit->bankAccount : '',
            'variable_symbol' => $reservation->getPaymentVariableSymbol() ?? '',
            'deposit_qr' => $this->depositQr($reservation, $deposit),
        ];

        return array_merge($values, array_intersect_key($context, self::VARIABLES));
    }

    private function firstName(?string $name): string
    {
        $tokens = $this->nameTokens($name);

        return $tokens[0] ?? '';
    }

    /** Příjmení = poslední slovo; jednoslovné jméno příjmení nemá. */
    private function lastName(?string $name): string
    {
        $tokens = $this->nameTokens($name);

        return \count($tokens) < 2 ? '' : (string) end($tokens);
    }

    /** @return list<string> */
    private function nameTokens(?string $name): array
    {
        return array_values(array_filter(explode(' ', trim((string) $name)), static fn (string $t): bool => $t !== ''));
    }

    private function time(?\DateTimeImmutable $time, string $fallback): string
    {
        return $time?->format('H:i') ?? $fallback;
    }

    private function nights(Reservation $reservation): int
    {
        $checkOut = $reservation->getCheckOut();

        return $checkOut !== null ? $reservation->getCheckIn()->diff($checkOut)->days : 0;
    }

    /**
     * Výše zálohy — haléře jen když nejsou nulové (procentní záloha), ať se text
     * shoduje s částkou v QR kódu (`AM:%.2F`) i na bankovním převodu.
     */
    private function depositAmount(string $amount): string
    {
        $value = (float) $amount;
        $decimals = fmod($value, 1.0) === 0.0 ? 0 : 2;

        return number_format($value, $decimals, ',', "\u{00a0}") . "\u{00a0}Kč";
    }

    private function money(?string $amount, string $currency): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }
        $formatted = number_format((float) $amount, 0, ',', "\u{00a0}");

        return $formatted . "\u{00a0}" . Money::symbol($currency);
    }

    private function address(?\App\Entity\AccommodationProfile $profile): string
    {
        if ($profile === null) {
            return '';
        }
        $street = trim(($profile->getUlice() ?? '') . ' ' . ($profile->getCp() ?? ''));
        $cityLine = trim($profile->getPsc() . ' ' . $profile->getObec());

        return trim($street . ', ' . $cityLine, ', ');
    }

    /**
     * QR platba jako Markdownový obrázek na veřejný PNG endpoint (CommonMark má
     * html_input: escape, přímé <img> by zescapoval; obrázek se navíc servíruje
     * z URL, protože mailoví klienti blokují data: URI). Prázdné, když zálohu nelze
     * zaplatit QR kódem (chybí IBAN) nebo rezervace není uložená (náhled/test).
     */
    private function depositQr(Reservation $reservation, ?DepositPayment $deposit): string
    {
        $id = $reservation->getId();
        $token = $reservation->getCheckinToken();
        if ($deposit === null || $deposit->spayd === null || $id === null || $id <= 0 || $token === null) {
            return '';
        }

        $url = $this->urlGenerator->generate('qr_deposit', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

        return sprintf('![QR platba zálohy](%s)', $url);
    }

    private function checkinUrl(Reservation $reservation): string
    {
        $token = $reservation->getCheckinToken();
        if ($token === null) {
            return '';
        }

        return $this->urlGenerator->generate(
            'checkin_index',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
