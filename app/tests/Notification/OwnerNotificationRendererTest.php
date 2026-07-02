<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\PendingOwnerNotification;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\OwnerNotificationMode;
use App\Enum\OwnerNotificationType;
use App\Notification\OwnerNotificationRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OwnerNotificationRendererTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OwnerNotificationRenderer $renderer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->renderer = $container->get(OwnerNotificationRenderer::class);
    }

    public function testNewReservationContent(): void
    {
        $reservation = $this->reservation(Channel::BOOKING);
        $content = $this->renderer->contentFor($this->notification(OwnerNotificationType::NEW_RESERVATION, $reservation));

        self::assertStringContainsString('Nová rezervace', $content->subject);
        self::assertStringContainsString($reservation->getChannel()->label(), $content->subject);
        self::assertStringContainsString('Testovací Host', $content->bodyMarkdown);
        self::assertStringContainsString('/reservation/' . $reservation->getId(), $content->bodyMarkdown);
    }

    public function testPaymentReceivedContentShowsAmount(): void
    {
        $content = $this->renderer->contentFor($this->notification(
            OwnerNotificationType::PAYMENT_RECEIVED,
            $this->reservation(Channel::WEB),
            ['amount' => '1000.00', 'currency' => 'CZK'],
        ));

        self::assertStringContainsString('Přišla platba', $content->subject);
        self::assertStringContainsString('1 000,00 Kč', $content->bodyMarkdown);
    }

    public function testVatReminderContentTranslatesMonth(): void
    {
        $content = $this->renderer->contentFor($this->notification(
            OwnerNotificationType::VAT_REMINDER,
            null,
            ['period' => '2026-06'],
        ));

        self::assertStringContainsString('červen 2026', $content->subject);
        self::assertStringContainsString('DPH přiznání', $content->bodyMarkdown);
    }

    public function testDigestCombinesItems(): void
    {
        $reservation = $this->reservation(Channel::AIRBNB);
        $rendered = $this->renderer->renderDigest([
            $this->notification(OwnerNotificationType::NEW_RESERVATION, $reservation),
            $this->notification(OwnerNotificationType::VAT_REMINDER, null, ['period' => '2026-06']),
        ]);

        self::assertStringContainsString('Souhrn notifikací (2)', $rendered->subject);
        self::assertStringContainsString('Nová rezervace', $rendered->html);
        self::assertStringContainsString('červen 2026', $rendered->html);
    }

    public function testRenderSingleProducesHtml(): void
    {
        $rendered = $this->renderer->renderSingle($this->notification(
            OwnerNotificationType::CHECKIN_COMPLETED,
            $this->reservation(Channel::WEB),
            ['documents' => 2],
        ));

        self::assertStringContainsString('<html', $rendered->html);
        self::assertStringContainsString('check-in', $rendered->html);
    }

    public function testTextPartHasNoRawButtonTokens(): void
    {
        // Textová část nesmí obsahovat syrové [[button:...]] — převedou se na „Popisek: url".
        $rendered = $this->renderer->renderSingle($this->notification(
            OwnerNotificationType::NEW_RESERVATION,
            $this->reservation(Channel::BOOKING),
        ));

        self::assertStringNotContainsString('[[button', $rendered->text);
        self::assertStringContainsString('Otevřít rezervaci: http', $rendered->text);
    }

    public function testRenderTestProducesMessage(): void
    {
        $rendered = $this->renderer->renderTest();

        self::assertStringContainsString('Testovací', $rendered->subject);
        self::assertStringContainsString('SMTP', $rendered->text);
        self::assertStringContainsString('<html', $rendered->html);
    }

    /** @param array<string, mixed> $payload */
    private function notification(OwnerNotificationType $type, ?Reservation $reservation, array $payload = []): PendingOwnerNotification
    {
        return new PendingOwnerNotification($type, OwnerNotificationMode::IMMEDIATE, $reservation, $payload);
    }

    private function reservation(Channel $channel): Reservation
    {
        $r = new Reservation($channel, new \DateTimeImmutable('2026-08-15'));
        $r->setGuestName('Testovací Host');
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }
}
