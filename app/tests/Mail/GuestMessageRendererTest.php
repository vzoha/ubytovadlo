<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Entity\MessageTemplate;
use App\Entity\Setting;
use App\Enum\MessageKind;
use App\Mail\GuestMessageRenderer;
use App\Mail\SampleReservationFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GuestMessageRendererTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GuestMessageRenderer $renderer;
    private SampleReservationFactory $sampleFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->renderer = $container->get(GuestMessageRenderer::class);
        $this->sampleFactory = $container->get(SampleReservationFactory::class);

        $this->em->createQuery('DELETE FROM ' . MessageTemplate::class . ' t')->execute();
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s')->execute();
    }

    public function testRendersDefaultTemplateIntoLayout(): void
    {
        $rendered = $this->renderer->render(MessageKind::PRE_ARRIVAL, $this->sampleFactory->create());

        self::assertNotSame('', $rendered->subject);
        self::assertStringContainsString('Jan', $rendered->html);
        self::assertStringContainsString('<table', $rendered->html);
        // výchozí téma „slate" – primární barva v záhlaví
        self::assertStringContainsString('#334155', $rendered->html);
        // Markdown odrážky se převedly na HTML
        self::assertStringContainsString('<li>', $rendered->html);
    }

    public function testRendersCtaButtonFromMarkdownToken(): void
    {
        $template = new MessageTemplate(
            MessageKind::CUSTOM,
            'Předmět',
            "Ahoj,\n\n[[button:Dokončit check-in|https://example.com/checkin]]\n\nDěkujeme.",
        );

        $rendered = $this->renderer->renderTemplate($template, $this->sampleFactory->create());

        // tlačítko jako table-based odkaz, ne escapovaný text
        self::assertStringContainsString('href="https://example.com/checkin"', $rendered->html);
        self::assertStringContainsString('Dokončit check-in', $rendered->html);
        self::assertStringNotContainsString('[[button:', $rendered->html);
        // akcent výchozího tématu „slate"
        self::assertStringContainsString('bgcolor="#0ea5e9"', $rendered->html);
    }

    public function testCtaButtonEscapesLabelAndUrl(): void
    {
        $template = new MessageTemplate(
            MessageKind::CUSTOM,
            'Předmět',
            '[[button:<script>x</script>|https://example.com/?a=1&b=2]]',
        );

        $rendered = $this->renderer->renderTemplate($template, $this->sampleFactory->create());

        self::assertStringNotContainsString('<script>x</script>', $rendered->html);
        self::assertStringContainsString('&amp;b=2', $rendered->html);
    }

    public function testDatabaseOverrideWins(): void
    {
        $override = new MessageTemplate(MessageKind::POST_STAY, 'Vlastní předmět', 'Tělo pro {{ guest_first_name }}.');
        $this->em->persist($override);
        $this->em->flush();

        $rendered = $this->renderer->render(MessageKind::POST_STAY, $this->sampleFactory->create());

        self::assertSame('Vlastní předmět', $rendered->subject);
        self::assertStringContainsString('Tělo pro Jan.', $rendered->html);
    }
}
