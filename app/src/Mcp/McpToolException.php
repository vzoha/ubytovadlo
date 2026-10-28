<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp;

/** Chyba volání nástroje, kterou model uvidí jako výsledek s `isError` (ne jako chybu protokolu). */
final class McpToolException extends \RuntimeException
{
}
