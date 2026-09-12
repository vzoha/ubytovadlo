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
use App\Formatting\GuestDate;
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
    /**
     * Proměnné rozdělené do sekcí palety v UI. Pořadí sekcí i proměnných v nich
     * je pořadí, ve kterém je majitel uvidí.
     *
     * @var array<string, array<string, string>> sekce => proměnná => popis
     */
    private const GROUPS = [
        'Host' => [
            'guest_name' => 'Celé jméno hosta',
            'guest_first_name' => 'Křestní jméno hosta',
            'guest_first_name_vocative' => 'Křestní jméno hosta v 5. pádu (oslovení)',
            'guest_last_name' => 'Příjmení hosta',
            'guest_last_name_vocative' => 'Příjmení hosta v 5. pádu (oslovení)',
        ],
        'Pobyt' => [
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
        ],
        'Ubytování' => [
            'accommodation_name' => 'Název ubytování',
            'accommodation_address' => 'Adresa ubytování',
        ],
        'Online check-in' => [
            'checkin_url' => 'Odkaz na online check-in',
            'checkin_lookup_url' => 'Odkaz na check-in pro zadání kódu (stejný pro všechny hosty)',
            'checkin_code' => 'Kód rezervace, kterým se host do check-inu dostane',
        ],
        'Záloha' => [
            'deposit_amount' => 'Výše zálohy k zaplacení',
            'deposit_due' => 'Splatnost zálohy (datum)',
            'bank_account' => 'Číslo účtu pro platbu zálohy',
            'variable_symbol' => 'Variabilní symbol platby zálohy',
            'deposit_qr' => 'QR kód pro platbu zálohy (obrázek)',
        ],
        'Faktura' => [
            'invoice_number' => 'Číslo faktury',
            'invoice_total' => 'Částka na faktuře',
            'invoice_payment_status' => 'Stav úhrady („Uhrazeno 7. 9. 2026“ / „K úhradě do 20. 9. 2026“)',
            'invoice_due' => 'Splatnost faktury (datum)',
            'invoice_bank_account' => 'Číslo účtu z faktury',
            'invoice_variable_symbol' => 'Variabilní symbol z faktury',
            'invoice_qr' => 'QR kód pro platbu faktury (obrázek)',
        ],
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
    private const NON_TEXT_VARIABLES = ['deposit_qr', 'invoice_qr'];

    /** @return array<string, string> název => popis */
    public static function variables(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /** @return array<string, array<string, string>> sekce => proměnná => popis */
    public static function groupedVariables(): array
    {
        return self::GROUPS;
    }

    /**
     * Podmnožina proměnných vhodná pro prostý text (SMS/WhatsApp) — bez obrázků.
     *
     * @return array<string, string> název => popis
     */
    public static function plainTextVariables(): array
    {
        return array_diff_key(self::variables(), array_flip(self::NON_TEXT_VARIABLES));
    }

    /**
     * Sekce palety pro prostý text — bez obrázků a bez sekcí, které tím zůstanou prázdné.
     *
     * @return array<string, array<string, string>> sekce => proměnná => popis
     */
    public static function plainTextGroupedVariables(): array
    {
        $groups = [];
        foreach (self::GROUPS as $group => $variables) {
            $variables = array_diff_key($variables, array_flip(self::NON_TEXT_VARIABLES));
            if ($variables !== []) {
                $groups[$group] = $variables;
            }
        }

        return $groups;
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
     * Tělo zprávy — dosazení plus úklid: řádky, na kterých zůstaly jen prázdné
     * proměnné, vypadnou i s mezerou po sobě. Šablona tak nepotřebuje podmínky,
     * aby se u faktury bez splatnosti neposlal holý popisek.
     *
     * @param array<string, string> $context
     */
    public function renderBody(string $text, Reservation $reservation, array $context = []): string
    {
        $values = $this->values($reservation, $context);

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $rendered = $this->renderLine($line, $values);
            if ($rendered !== null) {
                $lines[] = $rendered;
            }
        }

        return (string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));
    }

    /**
     * Řádek s dosazenými hodnotami, nebo `null`, když na něm byly jen proměnné
     * a všechny zůstaly prázdné — z takového řádku by v textu zbyl osiřelý
     * popisek („Splatnost:“ u faktury bez splatnosti).
     *
     * @param array<string, string> $values
     */
    private function renderLine(string $line, array $values): ?string
    {
        $known = 0;
        $filled = 0;
        $rendered = (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            static function (array $m) use ($values, &$known, &$filled): string {
                if (!isset($values[$m[1]])) {
                    return $m[0];
                }
                $known++;
                if ($values[$m[1]] !== '') {
                    $filled++;
                }

                return $values[$m[1]];
            },
            $line,
        );

        return $known > 0 && $filled === 0 ? null : $rendered;
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
        $checkOut = $reservation->getCheckOut();

        $values = [
            'guest_name' => $reservation->getGuestName() ?? '',
            'guest_first_name' => $this->firstName($reservation->getGuestName()),
            'guest_first_name_vocative' => $this->vocative->firstName($reservation->getGuestName()),
            'guest_last_name' => $this->lastName($reservation->getGuestName()),
            'guest_last_name_vocative' => $this->vocative->lastName($reservation->getGuestName()),
            'check_in' => GuestDate::format($reservation->getCheckIn()),
            'check_in_time' => $this->time($reservation->getCheckInTime(), self::DEFAULT_CHECK_IN_TIME),
            'check_out' => $checkOut !== null ? GuestDate::format($checkOut) : '',
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
            'accommodation_address' => $profile?->getAddress()->format() ?? '',
            'checkin_url' => $this->checkinUrl($reservation),
            'checkin_lookup_url' => $this->urlGenerator->generate('checkin_lookup', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'checkin_code' => $reservation->getExternalId() ?? $reservation->getMotopressExternalId() ?? '',
            'invoice_number' => '',
            'invoice_total' => '',
            'invoice_payment_status' => '',
            'invoice_due' => '',
            'invoice_bank_account' => '',
            'invoice_variable_symbol' => '',
            'invoice_qr' => '',
            'deposit_amount' => $deposit !== null ? $this->depositAmount($deposit->amount) : '',
            'deposit_due' => $deposit !== null ? GuestDate::format($deposit->dueDate) : '',
            'bank_account' => $deposit !== null ? $deposit->bankAccount : '',
            'variable_symbol' => $reservation->getPaymentVariableSymbol() ?? '',
            'deposit_qr' => $this->depositQr($reservation, $deposit),
        ];

        return array_merge($values, array_intersect_key($context, self::variables()));
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
