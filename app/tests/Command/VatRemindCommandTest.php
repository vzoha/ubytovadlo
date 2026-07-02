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
use App\Enum\OwnerNotificationType;
use App\Notification\OwnerNotificationSettingsProvider;
use App\Repository\PendingOwnerNotificationRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class VatRemindCommandTest extends KernelTestCase
{
    private const LAST_PERIOD_KEY = 'notifications.vat_reminder.last_period';

    private EntityManagerInterface $em;
    private PendingOwnerNotificationRepository $pending;
    private SettingRepository $settings;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->pending = $container->get(PendingOwnerNotificationRepository::class);
        $this->settings = $container->get(SettingRepository::class);

        $this->em->createQuery('DELETE FROM ' . PendingOwnerNotification::class . ' n')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s WHERE s.key IN (:keys)')
            ->setParameter('keys', [OwnerNotificationSettingsProvider::RECIPIENT, self::LAST_PERIOD_KEY])
            ->execute();
        $this->settings->set(OwnerNotificationSettingsProvider::RECIPIENT, 'ja@example.cz');
        $this->em->flush();

        $this->application = new Application(self::$kernel);
    }

    public function testRemindsWhenCommissionPresentAndGuardsAgainstRepeat(): void
    {
        $this->commissionedReservation('2026-06-15');

        $this->runCommand(['--month' => '2026-06', '--force' => true]);

        $queued = $this->pending->findAll();
        self::assertCount(1, $queued);
        self::assertSame(OwnerNotificationType::VAT_REMINDER, $queued[0]->getType());
        self::assertSame(['period' => '2026-06'], $queued[0]->getPayload());
        self::assertSame('2026-06', $this->settings->getString(self::LAST_PERIOD_KEY));

        // Bez --force stejné období už podruhé nezaloží.
        $this->runCommand(['--month' => '2026-06']);
        self::assertCount(1, $this->pending->findAll());
    }

    public function testNoReminderWhenNoCommission(): void
    {
        // Rezervace bez provize v daném měsíci → není co připomínat.
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-06-10'));
        $r->setCheckOut(new \DateTimeImmutable('2026-06-15'));
        $this->em->persist($r);
        $this->em->flush();

        $this->runCommand(['--month' => '2026-06', '--force' => true]);

        self::assertSame([], $this->pending->findAll());
        self::assertNull($this->settings->getString(self::LAST_PERIOD_KEY));
    }

    /** @param array<string, mixed> $input */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester($this->application->find('app:vat:remind'));
        $tester->execute($input);

        return $tester;
    }

    private function commissionedReservation(string $checkout): void
    {
        $r = new Reservation(Channel::BOOKING, new \DateTimeImmutable($checkout . ' -3 days'));
        $r->setCheckOut(new \DateTimeImmutable($checkout));
        $r->setGuestName('Provizní Host');
        $r->setCommissionAmount('360.00');
        $r->setCommissionCurrency('CZK');
        $this->em->persist($r);
        $this->em->flush();
    }
}
