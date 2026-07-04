<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\MotoPress;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Reservation\GuestRequestKeywords;

/**
 * Mapuje MotoPress booking JSON na Reservation entitu.
 *
 * Dva rezimy:
 *  - applyWebBooking: realny prodej z webu. Pri zakladani ($isNew) naplni vse;
 *    u uz existujici rezervace jen DOPLNI prazdna pole + datumy + storno —
 *    Ubytovadlo je autorita, MotoPress uz jen konektor (nikdy neprepise data hosta).
 *  - applyIcalBlock: pouze obsazenost (data, motopressExternalId), nikdy neprepisuje host data ani cenu
 *
 * @phpstan-type MphbBooking array<string, mixed>
 */
class MotoPressBookingMapper
{
    /** @var list<int> */
    private readonly array $petServiceIds;

    /** @var list<int> */
    private readonly array $babyCotServiceIds;

    /**
     * @param array<int|string, scalar> $motopressPetServiceIds     IDs MotoPress sluzeb znaci "host se psem"
     * @param array<int|string, scalar> $motopressBabyCotServiceIds IDs MotoPress sluzeb znaci "host potrebuje detskou postylku"
     */
    public function __construct(array $motopressPetServiceIds = [], array $motopressBabyCotServiceIds = [])
    {
        $this->petServiceIds = $this->normalizeIds($motopressPetServiceIds);
        $this->babyCotServiceIds = $this->normalizeIds($motopressBabyCotServiceIds);
    }

    /**
     * @param array<int|string, scalar> $raw
     *
     * @return list<int>
     */
    private function normalizeIds(array $raw): array
    {
        $ids = [];
        foreach ($raw as $id) {
            $int = (int) $id;
            if ($int > 0) {
                $ids[] = $int;
            }
        }

        return $ids;
    }

    /**
     * @param MphbBooking $data
     * @param bool        $isNew zakladame novou rezervaci (naplni vse), nebo jen dotahujeme
     *                           existujici (doplni prazdna pole + datumy + storno)
     */
    public function applyWebBooking(Reservation $reservation, array $data, bool $isNew = true): void
    {
        // Datumy (obsazenost) bereme z MotoPressu vzdy — host je muze na webu zmenit.
        $this->applyDates($reservation, $data);
        $bookedAt = $this->parseBookedAt($data);
        if ($bookedAt !== null && ($isNew || $reservation->getBookedAt() === null)) {
            $reservation->setBookedAt($bookedAt);
        }
        // Stav: pri zalozeni mapujeme plne; u existujici jen storno (zbytek rizne Ubytovadlo).
        $status = $this->mapStatus($data['status'] ?? null);
        if ($isNew) {
            $reservation->setStatus($status);
        } elseif ($status === ReservationStatus::CANCELLED) {
            $reservation->setStatus(ReservationStatus::CANCELLED);
        }

        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
        if ($name !== '' && $this->fill($isNew, $reservation->getGuestName())) {
            $reservation->setGuestName($name);
        }
        if (!empty($customer['email']) && $this->fill($isNew, $reservation->getGuestEmail())) {
            $reservation->setGuestEmail((string) $customer['email']);
        }
        if (!empty($customer['phone']) && $this->fill($isNew, $reservation->getGuestPhone())) {
            $reservation->setGuestPhone((string) $customer['phone']);
        }

        $street = trim((string) ($customer['address1'] ?? ''));
        $address2 = trim((string) ($customer['address2'] ?? ''));
        if ($address2 !== '') {
            $street = $street !== '' ? $street . ' ' . $address2 : $address2;
        }
        if ($street !== '' && $this->fill($isNew, $reservation->getGuestStreet())) {
            $reservation->setGuestStreet($street);
        }
        $city = trim((string) ($customer['city'] ?? ''));
        if ($city !== '' && $this->fill($isNew, $reservation->getGuestCity())) {
            $reservation->setGuestCity($city);
        }
        $zip = trim((string) ($customer['zip'] ?? ''));
        if ($zip !== '' && $this->fill($isNew, $reservation->getGuestZip())) {
            $reservation->setGuestZip($this->normalizeZip($zip));
        }
        $country = trim((string) ($customer['country'] ?? ''));
        if ($country !== '' && $this->fill($isNew, $reservation->getGuestCountry())) {
            $reservation->setGuestCountry($country);
        }

        $accommodations = is_array($data['reserved_accommodations'] ?? null) ? $data['reserved_accommodations'] : [];
        [$adults, $children] = $this->sumGuests($accommodations);
        // MotoPress neumí rozlišit děti od dospělých — pokud majitelka split opravila ručně,
        // další sync ho nepřepíše. U existující rezervace počty nedotahujeme (drží je Ubytovadlo).
        if ($isNew && !$reservation->isGuestsSplitManually()) {
            if ($adults > 0) {
                $reservation->setGuestsAdult($adults);
            }
            if ($children > 0) {
                $reservation->setGuestsChild($children);
            }
        }

        if (isset($data['total_price']) && $this->fill($isNew, $reservation->getPriceTotal())) {
            $reservation->setPriceTotal(number_format((float) $data['total_price'], 2, '.', ''));
            $reservation->setPriceCurrency(is_string($data['currency'] ?? null) && $data['currency'] !== '' ? (string) $data['currency'] : 'CZK');
        }

        // Služby a poznámka jen při zakládání — jednou dotažené drží Ubytovadlo.
        if (!$isNew) {
            return;
        }

        $topLevelServices = is_array($data['services'] ?? null) ? $data['services'] : [];

        $petService = $this->findPetService($accommodations, $topLevelServices);
        if ($petService !== null) {
            $reservation->setHasPet(true);
            $reservation->setPetsNote($petService);
        }

        if ($this->hasBabyCotService($accommodations, $topLevelServices)) {
            $reservation->setNeedsBabyCot(true);
        }

        if (!empty($data['note'])) {
            $note = (string) $data['note'];
            $reservation->setNotes($note);
            if ($petService === null && GuestRequestKeywords::mentionsPet($note)) {
                // fallback - host nezvolil placenou sluzbu, ale napsal to do poznamky
                $reservation->setHasPet(true);
                $reservation->setPetsNote($note);
            }
            if (!$reservation->needsBabyCot() && GuestRequestKeywords::mentionsBabyCot($note)) {
                $reservation->setNeedsBabyCot(true);
            }
        }
    }

    /** Doplnit pole? Ano při zakládání, nebo když je stávající hodnota prázdná. */
    private function fill(bool $isNew, ?string $current): bool
    {
        return $isNew || $current === null || $current === '';
    }

    /**
     * Detekuje placenou sluzbu typu "Pes" v rezervaci. MotoPress REST vraci
     * sluzby v `reserved_accommodations[*].services[]` jako pole `{id, price}`
     * (bez nazvu), takze rozhodujeme podle ID nakonfigurovanych v .env.
     *
     * Pripadny `title`/`name` nebo top-level `services[]` umime taky
     * (uziva se ve fixturach a starsim REST schema).
     *
     * @param array<int, mixed> $accommodations
     * @param array<int, mixed> $topLevelServices
     */
    private function findPetService(array $accommodations, array $topLevelServices): ?string
    {
        foreach ($this->iterServices($accommodations, $topLevelServices) as [$id, $title]) {
            if (in_array($id, $this->petServiceIds, true)) {
                return $title !== '' ? $title : 'Pes';
            }
            if ($title !== '' && GuestRequestKeywords::mentionsPet($title)) {
                return $title;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $accommodations
     * @param array<int, mixed> $topLevelServices
     */
    private function hasBabyCotService(array $accommodations, array $topLevelServices): bool
    {
        foreach ($this->iterServices($accommodations, $topLevelServices) as [$id, $title]) {
            if (in_array($id, $this->babyCotServiceIds, true)) {
                return true;
            }
            if ($title !== '' && GuestRequestKeywords::mentionsBabyCot($title)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sjednoceny pruchod pres top-level a per-accommodation sluzby.
     * Yieldne `[id, title]` pro kazdou sluzbu (id=0 pokud chybi, title='' pokud chybi).
     *
     * @param array<int, mixed> $accommodations
     * @param array<int, mixed> $topLevelServices
     *
     * @return \Generator<int, array{0: int, 1: string}>
     */
    private function iterServices(array $accommodations, array $topLevelServices): \Generator
    {
        foreach ($topLevelServices as $service) {
            $tuple = $this->normalizeService($service);
            if ($tuple !== null) {
                yield $tuple;
            }
        }
        foreach ($accommodations as $a) {
            if (!is_array($a) || !is_array($a['services'] ?? null)) {
                continue;
            }
            foreach ($a['services'] as $service) {
                $tuple = $this->normalizeService($service);
                if ($tuple !== null) {
                    yield $tuple;
                }
            }
        }
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private function normalizeService(mixed $service): ?array
    {
        if (!is_array($service)) {
            return null;
        }
        $id = isset($service['id']) ? (int) $service['id'] : 0;
        $title = (string) ($service['title'] ?? $service['name'] ?? '');

        return [$id, trim($title)];
    }

    /**
     * @param MphbBooking $data
     */
    public function applyIcalBlock(Reservation $reservation, array $data): void
    {
        // iCal block je jen blokator obsazenosti. Bere se z nej:
        //  - check_in / check_out (MotoPress je source of truth pro dostupnost)
        //  - motopress_external_id
        // Hostovy/penize NIKDY z iCal bloku.
        $this->applyDates($reservation, $data);

        if (isset($data['id'])) {
            $reservation->setMotopressExternalId((string) $data['id']);
        }

        // U iCal bloku je date_created_utc cas, kdy MotoPress poprve nasal block
        // (~kdy host objednal na OTA + poll interval). Lepsi proxy nemame.
        if ($reservation->getBookedAt() === null) {
            $bookedAt = $this->parseBookedAt($data);
            if ($bookedAt !== null) {
                $reservation->setBookedAt($bookedAt);
            }
        }
    }

    /**
     * @param MphbBooking $data
     */
    private function applyDates(Reservation $reservation, array $data): void
    {
        $checkIn = $this->parseDate($data['check_in_date'] ?? null);
        if ($checkIn !== null) {
            $reservation->setCheckIn($checkIn);
        }
        $checkOut = $this->parseDate($data['check_out_date'] ?? null);
        if ($checkOut !== null) {
            $reservation->setCheckOut($checkOut);
        }
        $checkInTime = $this->parseTime($data['check_in_time'] ?? null);
        if ($checkInTime !== null) {
            $reservation->setCheckInTime($checkInTime);
        }
        $checkOutTime = $this->parseTime($data['check_out_time'] ?? null);
        if ($checkOutTime !== null) {
            $reservation->setCheckOutTime($checkOutTime);
        }
    }

    /**
     * @param MphbBooking $data
     */
    private function parseBookedAt(array $data): ?\DateTimeImmutable
    {
        // Doctrine datetime sloupec nenese timezone: ukláda wall-clock a čte zpět
        // v default tz (Europe/Prague). Objekt proto vždy převedeme do default tz,
        // aby zápis == čtení a sync nehlásil věčné "upraveno".
        $local = new \DateTimeZone(date_default_timezone_get());

        $utc = $data['date_created_utc'] ?? null;
        if (is_string($utc) && $utc !== '') {
            try {
                return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($local);
            } catch (\Exception) {
            }
        }
        $raw = $data['date_created'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                return new \DateTimeImmutable($raw, $local);
            } catch (\Exception) {
            }
        }

        return null;
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable('1970-01-01 ' . $value);
        } catch (\Exception) {
            return null;
        }
    }

    private function mapStatus(mixed $status): ReservationStatus
    {
        if (!is_string($status)) {
            return ReservationStatus::NEEDS_DETAILS;
        }

        return match (strtolower($status)) {
            'confirmed', 'completed', 'check-in' => ReservationStatus::CONFIRMED,
            'cancelled', 'abandoned' => ReservationStatus::CANCELLED,
            default => ReservationStatus::NEEDS_DETAILS,
        };
    }

    private function normalizeZip(string $zip): string
    {
        $digits = preg_replace('/\D/', '', $zip) ?? '';
        if (strlen($digits) === 5) {
            return substr($digits, 0, 3) . ' ' . substr($digits, 3);
        }

        return $zip;
    }

    /**
     * @param array<int, mixed> $accommodations
     *
     * @return array{0: int, 1: int}
     */
    private function sumGuests(array $accommodations): array
    {
        $adults = 0;
        $children = 0;
        foreach ($accommodations as $a) {
            if (!is_array($a)) {
                continue;
            }
            $adults += (int) ($a['adults'] ?? 0);
            $children += (int) ($a['children'] ?? 0);
        }

        return [$adults, $children];
    }
}
