<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp;

/**
 * Co nástroj způsobí. Klient podle toho rozhoduje, jestli si před voláním
 * vyžádá souhlas uživatele.
 */
enum McpToolEffect
{
    /** Jen čte. */
    case READ;
    /** Změní stav v aplikaci, nic nemaže; opakované volání nic dalšího neudělá. */
    case CHANGE;
    /** Pošle něco mimo aplikaci (e-mail hostovi); opakované volání pošle znovu. */
    case SEND;

    /** @return array<string, bool> MCP tool annotations */
    public function annotations(): array
    {
        return match ($this) {
            self::READ => ['readOnlyHint' => true],
            self::CHANGE => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            self::SEND => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true],
        };
    }
}
