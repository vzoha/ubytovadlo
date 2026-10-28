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
use App\Repository\CustomerRepository;
use App\Repository\ReservationRepository;

/** Kdo nám píše: host podle e-mailu, telefonu nebo jména i s jeho pobyty. */
final class FindGuestTool implements McpTool
{
    private const LIMIT = 5;

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly ReservationRepository $reservations,
        private readonly ReservationPresenter $presenter,
    ) {
    }

    public function name(): string
    {
        return 'find_guest';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Najde hosta podle e-mailu, telefonu nebo části jména a vrátí jeho kontakt, '
            . 'poznámku ubytovatele a všechny pobyty (i zrušené, od nejnovějšího). '
            . 'Použij jako první krok, když odpovídáš na zprávu — podle adresy odesílatele zjistíš, '
            . 'jestli jde o stávající rezervaci nebo vracejícího se hosta.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'E-mail, telefon nebo jméno hosta'],
            ],
            'required' => ['query'],
        ];
    }

    public function call(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if (mb_strlen($query) < 3) {
            throw new McpToolException('Zadej aspoň 3 znaky (e-mail, telefon nebo jméno).');
        }

        $guests = [];
        foreach ($this->customers->findForList($query, self::LIMIT) as $row) {
            $customer = $row->customer;
            $guests[] = [
                'name' => $customer->getDisplayName(),
                'email' => $customer->getEmail(),
                'phone' => $customer->getPhone(),
                'ownerNote' => $customer->getNote(),
                'stays' => array_map($this->presenter->summary(...), $this->reservations->findAllOfCustomer($customer)),
            ];
        }

        return ['found' => count($guests), 'guests' => $guests];
    }
}
