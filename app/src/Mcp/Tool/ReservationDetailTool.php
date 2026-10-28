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
use App\Mcp\ReservationPresenter;
use App\Repository\ReservationRepository;

/** Detail jedné rezervace: kontakt, časy příjezdu, doplatek a historie komunikace. */
final class ReservationDetailTool implements McpTool
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ReservationPresenter $presenter,
    ) {
    }

    public function name(): string
    {
        return 'get_reservation';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Detail rezervace podle ID (z find_guest) nebo kódu, který host zná z potvrzení '
            . '(kód Bookingu/Airbnb, číslo rezervace z webu). Vrací kontakt, časy příjezdu a odjezdu, '
            . 'zvíře, poznámky, stav online check-inu, doplatek v CZK, historii komunikace z časové osy '
            . 'a e-maily přiřazené k rezervaci (text přes read_guest_email podle id).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'ID rezervace'],
                'code' => ['type' => 'string', 'description' => 'Kód rezervace z potvrzení'],
            ],
        ];
    }

    public function call(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        $code = mb_strtoupper(trim((string) ($arguments['code'] ?? '')));

        if ($id > 0) {
            $reservation = $this->reservations->find($id);
            if ($reservation === null) {
                throw new McpToolException(sprintf('Rezervace s ID %d neexistuje.', $id));
            }

            return $this->presenter->detail($reservation);
        }
        if ($code === '') {
            throw new McpToolException('Zadej ID nebo kód rezervace.');
        }

        $found = $this->reservations->findByGuestCode($code);
        if ($found === []) {
            throw new McpToolException(sprintf('Rezervace s kódem %s neexistuje.', $code));
        }

        return count($found) === 1
            ? $this->presenter->detail($found[0])
            : ['ambiguous' => true, 'matches' => array_map($this->presenter->summary(...), $found)];
    }
}
