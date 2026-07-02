<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\PendingOwnerNotification;
use App\Entity\Reservation;
use App\Entity\Setting;
use App\Enum\Channel;
use App\Enum\OwnerNotificationMode;
use App\Enum\OwnerNotificationType;
use App\Notification\OwnerNotificationSettingsProvider;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class NotificationsDispatchCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . PendingOwnerNotification::class . ' n')->execute();
        $this->em->createQuery('DELETE FROM ' . Setting::class . " s WHERE s.key = '" . OwnerNotificationSettingsProvider::RECIPIENT . "'")->execute();
        $container->get(SettingRepository::class)->set(OwnerNotificationSettingsProvider::RECIPIENT, 'ja@example.cz');
        $this->em->flush();

        $this->application = new Application(self::$kernel);
    }

    public function testImmediateNotificationIsSentAndMarkedAndIsIdempotent(): void
    {
        $notification = $this->enqueue(OwnerNotificationType::NEW_RESERVATION, OwnerNotificationMode::IMMEDIATE);

        $this->runCommand('app:notifications:dispatch');

        $this->em->refresh($notification);
        self::assertNotNull($notification->getSentAt(), 'Okamžitá notifikace se má označit odeslaná.');

        // Druhý běh už nic neposílá (sentAt filtruje).
        $sentAt = $notification->getSentAt();
        $this->runCommand('app:notifications:dispatch');
        $this->em->refresh($notification);
        self::assertEquals($sentAt, $notification->getSentAt());
    }

    public function testDispatchIgnoresDigestNotifications(): void
    {
        $digest = $this->enqueue(OwnerNotificationType::CHECKIN_COMPLETED, OwnerNotificationMode::DIGEST);

        $this->runCommand('app:notifications:dispatch');

        $this->em->refresh($digest);
        self::assertNull($digest->getSentAt(), 'Souhrnná notifikace nepatří do okamžitého rozeslání.');
    }

    public function testDigestSendsCollectedItemsOnce(): void
    {
        $a = $this->enqueue(OwnerNotificationType::CHECKIN_COMPLETED, OwnerNotificationMode::DIGEST);
        $b = $this->enqueue(OwnerNotificationType::VAT_REMINDER, OwnerNotificationMode::DIGEST, null, ['period' => '2026-06']);

        $this->runCommand('app:notifications:digest');

        $this->em->refresh($a);
        $this->em->refresh($b);
        self::assertNotNull($a->getSentAt());
        self::assertNotNull($b->getSentAt());

        $tester = $this->runCommand('app:notifications:digest');
        self::assertStringContainsString('nic k odeslání', $tester->getDisplay());
    }

    public function testDigestWithoutRecipientSkipsWithoutCrashOrLoss(): void
    {
        $item = $this->enqueue(OwnerNotificationType::CHECKIN_COMPLETED, OwnerNotificationMode::DIGEST);
        static::getContainer()->get(SettingRepository::class)
            ->set(OwnerNotificationSettingsProvider::RECIPIENT, '');
        $this->em->flush();

        $tester = $this->runCommand('app:notifications:digest');

        self::assertStringContainsString('příjemce', $tester->getDisplay());
        $this->em->refresh($item);
        self::assertNull($item->getSentAt(), 'Bez příjemce fronta zůstane nedotčená.');
    }

    private function runCommand(string $name): CommandTester
    {
        $tester = new CommandTester($this->application->find($name));
        $tester->execute([]);

        return $tester;
    }

    /** @param array<string, mixed>|null $payload */
    private function enqueue(OwnerNotificationType $type, OwnerNotificationMode $mode, ?Reservation $reservation = null, ?array $payload = null): PendingOwnerNotification
    {
        $reservation ??= $this->reservation();
        $notification = new PendingOwnerNotification($type, $mode, $reservation, $payload);
        $this->em->persist($notification);
        $this->em->flush();

        return $notification;
    }

    private function reservation(): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('+10 days'));
        $r->setGuestName('Testovací Host');
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }
}
