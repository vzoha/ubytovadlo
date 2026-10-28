<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Nástroj, který AI asistent volá přes MCP. Popis a schéma čte model, podle nich
 * se rozhoduje, kdy nástroj použít — proto jsou psané pro něj, ne pro vývojáře.
 */
#[AutoconfigureTag(self::TAG)]
interface McpTool
{
    public const TAG = 'app.mcp_tool';

    public function name(): string;

    public function description(): string;

    /**
     * JSON Schema vstupních argumentů.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> data, která se modelu vrátí jako JSON
     *
     * @throws McpToolException když argumenty nedávají smysl — model dostane zprávu a může to zkusit znovu
     */
    public function call(array $arguments): array;
}
