<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Mail\MessageVariableResolver;
use App\Mail\QuickMessageDefaults;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vzory rychlých zpráv míří do SMS, WhatsAppu a chatu portálů — musí mít text
 * a smí sahat jen po proměnných, které se vykreslí v prostém textu. Překlep
 * v názvu nebo obrázková proměnná by hostovi dorazily jako syrové {{ ... }}.
 */
final class QuickMessageDefaultsTest extends TestCase
{
    /** @param array{label: string, body: string} $template */
    #[DataProvider('templates')]
    public function testTemplateHasText(array $template): void
    {
        self::assertNotSame('', trim($template['label']));
        self::assertNotSame('', trim($template['body']));
    }

    /** @param array{label: string, body: string} $template */
    #[DataProvider('templates')]
    public function testTemplateUsesPlainTextVariablesOnly(array $template): void
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $template['body'], $matches);
        $known = MessageVariableResolver::plainTextVariables();

        $unknown = array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $name): bool => !isset($known[$name]),
        )));

        self::assertSame([], $unknown);
    }

    public function testSeededMessagesKeepTemplateOrder(): void
    {
        $messages = QuickMessageDefaults::create();

        self::assertCount(\count(QuickMessageDefaults::templates()), $messages);

        foreach ($messages as $order => $message) {
            self::assertSame($order, $message->getSortOrder());
        }
    }

    /** @return iterable<string, array{array{label: string, body: string}}> */
    public static function templates(): iterable
    {
        foreach (QuickMessageDefaults::templates() as $template) {
            yield $template['label'] => [$template];
        }
    }
}
