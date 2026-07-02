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
use App\Entity\Setting;
use App\Enum\Channel;
use App\Enum\OwnerNotificationMode;
use App\Enum\OwnerNotificationType;
use App\Notification\OwnerNotificationSettingsProvider;
use App\Notification\OwnerNotifier;
use App\Repository\PendingOwnerNotificationRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OwnerNotifierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OwnerNotifier $notifier;
    private SettingRepository $settings;
    private PendingOwnerNotificationRepository $pending;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->notifier = $container->get(OwnerNotifier::class);
        $this->settings = $container->get(SettingRepository::class);
        $this->pending = $container->get(PendingOwnerNotificationRepository::class);

        $this->em->createQuery('DELETE FROM ' . PendingOwnerNotification::class . ' n')->execute();
        $this->clearSettings();
    }

    public function testEnqueuesWhenRecipientSetAndTypeEnabled(): void
    {
        $this->configure('ja@example.cz', OwnerNotificationType::NEW_RESERVATION, OwnerNotificationMode::IMMEDIATE);
        $reservation = $this->reservation();

        self::assertTrue($this->notifier->notify(OwnerNotificationType::NEW_RESERVATION, $reservation));
        $this->em->flush();

        $queued = $this->pending->findAll();
        self::assertCount(1, $queued);
        self::assertSame(OwnerNotificationType::NEW_RESERVATION, $queued[0]->getType());
        self::assertSame(OwnerNotificationMode::IMMEDIATE, $queued[0]->getDeliveryMode());
        self::assertSame($reservation, $queued[0]->getReservation());
    }

    public function testDoesNothingWithoutRecipient(): void
    {
        // Bez příjemce se nic neeviduje, ani když je typ jinak zapnutý.
        $this->settings->set(OwnerNotificationSettingsProvider::modeKey(OwnerNotificationType::NEW_RESERVATION), OwnerNotificationMode::IMMEDIATE->value);
        $this->em->flush();

        self::assertFalse($this->notifier->notify(OwnerNotificationType::NEW_RESERVATION, $this->reservation()));
        $this->em->flush();

        self::assertSame([], $this->pending->findAll());
    }

    public function testDoesNothingWhenTypeOff(): void
    {
        $this->configure('ja@example.cz', OwnerNotificationType::PAYMENT_RECEIVED, OwnerNotificationMode::OFF);

        self::assertFalse($this->notifier->notify(OwnerNotificationType::PAYMENT_RECEIVED, $this->reservation()));
        $this->em->flush();

        self::assertSame([], $this->pending->findAll());
    }

    public function testStoresSnapshotOfModeAtEnqueueTime(): void
    {
        // Digest typ se uloží s digest režimem i po pozdější změně nastavení.
        $this->configure('ja@example.cz', OwnerNotificationType::CHECKIN_COMPLETED, OwnerNotificationMode::DIGEST);
        $this->notifier->notify(OwnerNotificationType::CHECKIN_COMPLETED, $this->reservation());
        $this->em->flush();

        self::assertSame(OwnerNotificationMode::DIGEST, $this->pending->findAll()[0]->getDeliveryMode());
    }

    private function configure(string $recipient, OwnerNotificationType $type, OwnerNotificationMode $mode): void
    {
        $this->settings->set(OwnerNotificationSettingsProvider::RECIPIENT, $recipient);
        $this->settings->set(OwnerNotificationSettingsProvider::modeKey($type), $mode->value);
        $this->em->flush();
    }

    private function clearSettings(): void
    {
        $keys = [OwnerNotificationSettingsProvider::RECIPIENT];
        foreach (OwnerNotificationType::cases() as $type) {
            $keys[] = OwnerNotificationSettingsProvider::modeKey($type);
        }
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s WHERE s.key IN (:keys)')
            ->setParameter('keys', $keys)
            ->execute();
    }

    private function reservation(): Reservation
    {
        $r = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('+10 days'));
        $r->setGuestName('Testovací Host');
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }
}
