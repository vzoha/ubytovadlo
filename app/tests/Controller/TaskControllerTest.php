<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\LedgerEntry;
use App\Entity\RecurringTask;
use App\Entity\TaskCompletion;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\TaskCategory;
use App\Enum\TaskIntervalUnit;
use App\Enum\TaskRecurrence;
use App\Repository\LedgerEntryRepository;
use App\Repository\RecurringTaskRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TaskControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RecurringTaskRepository $tasks;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->tasks = $container->get(RecurringTaskRepository::class);

        $this->em->createQuery('DELETE FROM ' . TaskCompletion::class . ' c')->execute();
        $this->em->createQuery('DELETE FROM ' . RecurringTask::class . ' t')->execute();
        $this->em->createQuery('DELETE FROM ' . LedgerEntry::class . ' e')->execute();
        $this->em->createQuery('DELETE FROM ' . Account::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();
        $this->em->flush();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('tasks-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'tasks-test@example.com']));
    }

    public function testEmptyListInvitesToCatalog(): void
    {
        $crawler = $this->client->request('GET', '/terminy');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Zatím žádné hlídané termíny', $crawler->filter('table')->text());
    }

    public function testTaskIsCreatedFromForm(): void
    {
        $this->client->request('POST', '/terminy/novy', [
            '_token' => $this->tokenFrom('/terminy', '/terminy/novy'),
            'name' => 'Revize elektroinstalace',
            'category' => TaskCategory::REVISION->value,
            'recurrence' => TaskRecurrence::INTERVAL->value,
            'interval_value' => '5',
            'interval_unit' => TaskIntervalUnit::YEAR->value,
            'due_on' => '2026-09-15',
            'lead_days' => '45',
            'vendor' => 'Revizní technik',
            'legal_reference' => 'ČSN 33 1500',
            'active' => '1',
        ]);

        self::assertResponseRedirects();
        $task = $this->tasks->findOneBy(['name' => 'Revize elektroinstalace']);
        self::assertNotNull($task);
        self::assertSame(5, $task->getIntervalValue());
        self::assertSame(45, $task->getLeadDays());
        self::assertSame('2026-09-15', $task->getDueOn()?->format('Y-m-d'));
    }

    public function testTaskWithoutNameIsRejected(): void
    {
        $this->client->request('POST', '/terminy/novy', [
            '_token' => $this->tokenFrom('/terminy', '/terminy/novy'),
            'name' => '  ',
            'category' => TaskCategory::REVISION->value,
        ]);

        self::assertResponseRedirects('/terminy');
        self::assertSame([], $this->tasks->findAll());
    }

    public function testCatalogAddsSelectedTemplatesOnlyOnce(): void
    {
        $payload = [
            '_token' => $this->tokenFrom('/terminy', '/terminy/katalog'),
            'keys' => ['chimney_sweep', 'fire_extinguisher_check'],
        ];

        $this->client->request('POST', '/terminy/katalog', $payload);
        self::assertCount(2, $this->tasks->findAll());

        $this->client->request('POST', '/terminy/katalog', $payload);
        self::assertCount(2, $this->tasks->findAll(), 'už hlídaný termín se nepřidá podruhé');

        $task = $this->tasks->findOneBy(['catalogKey' => 'chimney_sweep']);
        self::assertSame('vyhl. 34/2016 Sb.', $task?->getLegalReference());
    }

    public function testCompletionMovesDeadlineAndBooksExpense(): void
    {
        $task = $this->task();
        $account = $this->account();

        $this->client->request('POST', '/terminy/' . $task->getId() . '/provedeno', [
            '_token' => $this->tokenFrom('/terminy/' . $task->getId(), '/provedeno'),
            'done_on' => '2026-04-10',
            'vendor' => 'Kominictví',
            'cost' => '900',
            'book_account' => (string) $account->getId(),
            'note' => 'Vyčištěno',
        ]);

        self::assertResponseRedirects('/terminy/' . $task->getId());
        $this->em->clear();
        $reloaded = $this->tasks->find($task->getId());
        self::assertSame('2027-04-10', $reloaded?->getDueOn()?->format('Y-m-d'));

        $entries = static::getContainer()->get(LedgerEntryRepository::class)->findAll();
        self::assertCount(1, $entries);
        self::assertSame(900, $entries[0]->getAmountCzk());
    }

    public function testProtocolIsStoredAndServedBack(): void
    {
        $task = $this->task();
        $file = $this->pdfFile();

        $this->client->request(
            'POST',
            '/terminy/' . $task->getId() . '/provedeno',
            ['_token' => $this->tokenFrom('/terminy/' . $task->getId(), '/provedeno'), 'done_on' => '2026-04-10'],
            ['attachment' => $file],
        );
        self::assertResponseRedirects();

        $this->em->clear();
        $completion = $this->em->getRepository(TaskCompletion::class)->findOneBy([]);
        self::assertNotNull($completion);
        self::assertSame('protokol.pdf', $completion->getAttachmentName());

        $this->client->request('GET', '/terminy/provedeni/' . $completion->getId() . '/protokol');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/terminy/provedeni/' . $completion->getId() . '/smazat', [
            '_token' => $this->tokenFrom('/terminy/' . $task->getId(), '/provedeni/' . $completion->getId() . '/smazat'),
        ]);
        self::assertResponseRedirects();
    }

    public function testDetailShowsHistoryAndTaskCanBeDeleted(): void
    {
        $task = $this->task();

        $crawler = $this->client->request('GET', '/terminy/' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Kontrola a čištění spalinové cesty', $crawler->filter('h1')->text());

        $this->client->request('POST', '/terminy/' . $task->getId() . '/smazat', [
            '_token' => $this->tokenFrom('/terminy/' . $task->getId(), '/terminy/' . $task->getId() . '/smazat'),
        ]);

        self::assertResponseRedirects('/terminy');
        self::assertSame([], $this->tasks->findAll());
    }

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/terminy');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    private function task(): RecurringTask
    {
        $task = new RecurringTask('Kontrola a čištění spalinové cesty', TaskCategory::REVISION, TaskRecurrence::INTERVAL);
        $task->setInterval(1, TaskIntervalUnit::YEAR)->setDueOn(new \DateTimeImmutable('2026-05-01'));
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function account(): Account
    {
        $account = new Account('Provozní účet', AccountType::BANK, 0, new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function pdfFile(): UploadedFile
    {
        $path = sys_get_temp_dir() . '/protokol.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        return new UploadedFile($path, 'protokol.pdf', 'application/pdf', null, true);
    }

    /** CSRF token se čte z vykreslené stránky — jinde v testech se to dělá stejně. */
    private function tokenFrom(string $url, string $actionSuffix): string
    {
        $crawler = $this->client->request('GET', $url);

        return (string) $crawler->filter('form[action$="' . $actionSuffix . '"] input[name="_token"]')->first()->attr('value');
    }
}
