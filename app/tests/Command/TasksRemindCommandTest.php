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
use App\Entity\RecurringTask;
use App\Entity\Setting;
use App\Entity\TaskCompletion;
use App\Enum\OwnerNotificationType;
use App\Enum\TaskCategory;
use App\Enum\TaskIntervalUnit;
use App\Enum\TaskRecurrence;
use App\Notification\OwnerNotificationSettingsProvider;
use App\Repository\PendingOwnerNotificationRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class TasksRemindCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PendingOwnerNotificationRepository $pending;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->pending = $container->get(PendingOwnerNotificationRepository::class);

        $this->em->createQuery('DELETE FROM ' . PendingOwnerNotification::class . ' n')->execute();
        $this->em->createQuery('DELETE FROM ' . TaskCompletion::class . ' c')->execute();
        $this->em->createQuery('DELETE FROM ' . RecurringTask::class . ' t')->execute();
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s WHERE s.key = :key')
            ->setParameter('key', OwnerNotificationSettingsProvider::RECIPIENT)
            ->execute();
        $container->get(SettingRepository::class)->set(OwnerNotificationSettingsProvider::RECIPIENT, 'ja@example.cz');
        $this->em->flush();

        $this->application = new Application(self::$kernel);
    }

    public function testRemindsOnceWhenDeadlineEntersWindow(): void
    {
        $task = $this->task('2026-06-01', leadDays: 30);

        $this->runCommand('2026-05-20');
        self::assertCount(1, $this->queued());

        $this->runCommand('2026-05-21');
        self::assertCount(1, $this->queued(), 'na tentýž termín se upozorňuje jednou');

        $payload = $this->queued()[0]->getPayload();
        self::assertSame($task->getId(), $payload['taskId']);
        self::assertSame(12, $payload['days']);
        self::assertSame('vyhl. 246/2001 Sb.', $payload['legalReference']);
    }

    public function testDistantDeadlineIsNotAnnounced(): void
    {
        $this->task('2026-12-01', leadDays: 30);

        $this->runCommand('2026-05-20');

        self::assertSame([], $this->queued());
    }

    public function testOverdueTaskIsRepeatedWeekly(): void
    {
        $this->task('2026-05-01', leadDays: 30);

        $this->runCommand('2026-05-02');
        self::assertCount(1, $this->queued());

        $this->runCommand('2026-05-05');
        self::assertCount(1, $this->queued(), 'do týdne se připomínka neopakuje');

        $this->runCommand('2026-05-09');
        self::assertCount(2, $this->queued());
    }

    public function testDisabledTaskIsSkipped(): void
    {
        $this->task('2026-05-01')->setActive(false);
        $this->em->flush();

        $this->runCommand('2026-05-20');

        self::assertSame([], $this->queued());
    }

    public function testWithoutRecipientNothingIsQueued(): void
    {
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s WHERE s.key = :key')
            ->setParameter('key', OwnerNotificationSettingsProvider::RECIPIENT)
            ->execute();
        $this->em->clear();
        $this->task('2026-05-25');

        $this->runCommand('2026-05-20');

        self::assertSame([], $this->queued());
    }

    /** @return list<PendingOwnerNotification> */
    private function queued(): array
    {
        $this->em->clear();

        return array_values(array_filter(
            $this->pending->findAll(),
            static fn (PendingOwnerNotification $n): bool => $n->getType() === OwnerNotificationType::TASK_DUE,
        ));
    }

    private function task(string $dueOn, int $leadDays = 30): RecurringTask
    {
        $task = new RecurringTask('Kontrola hasicích přístrojů', TaskCategory::REVISION, TaskRecurrence::INTERVAL);
        $task->setInterval(1, TaskIntervalUnit::YEAR)
            ->setLeadDays($leadDays)
            ->setLegalReference('vyhl. 246/2001 Sb.')
            ->setDueOn(new \DateTimeImmutable($dueOn));
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function runCommand(string $today): void
    {
        $tester = new CommandTester($this->application->find('app:tasks:remind'));
        $tester->execute(['--today' => $today]);
        $tester->assertCommandIsSuccessful();
    }
}
