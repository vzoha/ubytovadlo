<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Enum\MessageKind;
use App\Mail\MessageLocales;
use App\Mail\MessageTemplateDefaults;
use App\Mail\MessageVariableResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Každý druh zprávy musí mít výchozí text ve všech jazycích — čerstvá instance
 * i nově přidaný druh zprávy tak fungují bez seedování a bez prázdného e-mailu.
 */
final class MessageTemplateDefaultsTest extends TestCase
{
    #[DataProvider('kinds')]
    public function testBaseLanguageHasText(MessageKind $kind): void
    {
        $template = MessageTemplateDefaults::for($kind);

        self::assertNotSame('', trim($template->getSubject()));
        self::assertNotSame('', trim($template->getBodyMarkdown()));
        self::assertSame(MessageLocales::BASE, $template->getLocale());
    }

    #[DataProvider('kinds')]
    public function testScheduledKindHasTiming(MessageKind $kind): void
    {
        $template = MessageTemplateDefaults::for($kind);

        if (!$kind->isScheduled()) {
            self::assertNull($template->getAnchor(), 'Neplánovaný druh kotvu nemá');

            return;
        }

        self::assertNotNull($template->getAnchor(), 'Plánovaný druh musí mít kotvu');
    }

    #[DataProvider('translations')]
    public function testEveryKindIsTranslated(MessageKind $kind, string $locale): void
    {
        $template = MessageTemplateDefaults::forLocale($kind, $locale);

        self::assertNotNull($template, sprintf('Chybí překlad %s do %s', $kind->value, $locale));
        self::assertNotSame('', trim($template->getSubject()));
        self::assertNotSame('', trim($template->getBodyMarkdown()));
        self::assertSame($locale, $template->getLocale());
    }

    /**
     * Překlep v názvu proměnné by hostovi dorazil jako syrové {{ ... }} —
     * výchozí texty smí sahat jen po proměnné, které paleta zná.
     */
    #[DataProvider('translations')]
    public function testTranslationUsesKnownVariablesOnly(MessageKind $kind, string $locale): void
    {
        $template = MessageTemplateDefaults::forLocale($kind, $locale);
        self::assertNotNull($template);

        self::assertSame([], $this->unknownVariables($template->getSubject() . "\n" . $template->getBodyMarkdown()));
    }

    #[DataProvider('kinds')]
    public function testBaseTextUsesKnownVariablesOnly(MessageKind $kind): void
    {
        $template = MessageTemplateDefaults::for($kind);

        self::assertSame([], $this->unknownVariables($template->getSubject() . "\n" . $template->getBodyMarkdown()));
    }

    /** @return list<string> */
    private function unknownVariables(string $text): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $matches);
        $known = MessageVariableResolver::variables();

        return array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $name): bool => !isset($known[$name]),
        )));
    }

    /** @return iterable<string, array{MessageKind}> */
    public static function kinds(): iterable
    {
        foreach (MessageKind::cases() as $kind) {
            yield $kind->value => [$kind];
        }
    }

    /** @return iterable<string, array{MessageKind, string}> */
    public static function translations(): iterable
    {
        foreach (MessageKind::cases() as $kind) {
            foreach (MessageLocales::translations() as $locale) {
                yield $kind->value . ' → ' . $locale => [$kind, $locale];
            }
        }
    }
}
