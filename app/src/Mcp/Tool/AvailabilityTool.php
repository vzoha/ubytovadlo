<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\McpTool;
use App\Mcp\McpToolException;
use App\Repository\ReservationRepository;
use Psr\Clock\ClockInterface;

/**
 * Je termín volný? Odpověď na nejčastější dotaz z e-mailu. Obsazené pobyty
 * vrací bez údajů hosta — model je k odpovědi nepotřebuje.
 */
final class AvailabilityTool implements McpTool
{
    private const MAX_DAYS = 400;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return 'check_availability';
    }

    public function description(): string
    {
        return 'Ověří, jestli je ubytování volné od data příjezdu do data odjezdu. Den odjezdu '
            . 'jednoho hosta a příjezdu dalšího se nepřekrývá. Vrací free a seznam obsazených pobytů '
            . 'v zadaném rozsahu (jen data a kanál), takže jde i najít nejbližší volné okno. '
            . 'Obsazenost zahrnuje všechny kanály (web, Booking, Airbnb i další portály).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'checkIn' => ['type' => 'string', 'format' => 'date', 'description' => 'Příjezd, RRRR-MM-DD'],
                'checkOut' => ['type' => 'string', 'format' => 'date', 'description' => 'Odjezd, RRRR-MM-DD'],
            ],
            'required' => ['checkIn', 'checkOut'],
        ];
    }

    public function call(array $arguments): array
    {
        $checkIn = self::date($arguments['checkIn'] ?? null, 'checkIn');
        $checkOut = self::date($arguments['checkOut'] ?? null, 'checkOut');
        if ($checkOut <= $checkIn) {
            throw new McpToolException('Odjezd musí být po příjezdu.');
        }
        if ($checkIn->diff($checkOut)->days > self::MAX_DAYS) {
            throw new McpToolException(sprintf('Rozsah může mít nejvýš %d dní.', self::MAX_DAYS));
        }

        $today = $this->clock->now()->setTime(0, 0);
        $occupied = [];
        foreach ($this->reservations->findOverlappingRange($checkIn, $checkOut) as $reservation) {
            // Repozitář bere i pobyt odjíždějící v den příjezdu (kalendář ho kreslí do poledne);
            // pro dostupnost noci se nepřekrývá.
            $end = $reservation->getCheckOut() ?? $reservation->getCheckIn()->modify('+1 day');
            if ($end <= $checkIn) {
                continue;
            }
            $occupied[] = [
                'checkIn' => $reservation->getCheckIn()->format('Y-m-d'),
                'checkOut' => $end->format('Y-m-d'),
                'channel' => $reservation->getChannel()->label(),
            ];
        }

        return [
            'checkIn' => $checkIn->format('Y-m-d'),
            'checkOut' => $checkOut->format('Y-m-d'),
            'nights' => (int) $checkIn->diff($checkOut)->days,
            'inPast' => $checkIn < $today,
            'free' => $occupied === [],
            'occupied' => $occupied,
        ];
    }

    private static function date(mixed $value, string $field): \DateTimeImmutable
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new McpToolException(sprintf('%s musí být datum ve tvaru RRRR-MM-DD.', $field));
        }

        return $date;
    }
}
