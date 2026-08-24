<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Concern\ChecksCsrf;
use App\Entity\RecurringTask;
use App\Entity\TaskCompletion;
use App\Enum\ExpenseCategory;
use App\Enum\TaskCategory;
use App\Enum\TaskIntervalUnit;
use App\Enum\TaskRecurrence;
use App\Repository\AccountRepository;
use App\Repository\RecurringTaskRepository;
use App\Task\TaskAttachmentStorage;
use App\Task\TaskCatalog;
use App\Task\TaskCompletionRecorder;
use App\Task\TaskFormHydrator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hlídané termíny — revize, servis, administrativa, platby a sezónní úkony.
 * Seznam se stavem termínu, historie provedení s protokoly a katalog obvyklých
 * termínů, ze kterého se dá hlídání zapnout i bez znalosti lhůt.
 */
class TaskController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly RecurringTaskRepository $tasks,
        private readonly AccountRepository $accounts,
        private readonly TaskCatalog $catalog,
        private readonly TaskFormHydrator $hydrator,
        private readonly TaskCompletionRecorder $recorder,
        private readonly TaskAttachmentStorage $attachments,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/terminy', name: 'task_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $category = TaskCategory::tryFrom((string) $request->query->get('kategorie', ''));
        $onlyActive = !$request->query->getBoolean('vse');
        $tasks = $onlyActive
            ? $this->tasks->findActiveForListing($category)
            : $this->tasks->findForListing($category);
        $today = new \DateTimeImmutable('today');

        usort(
            $tasks,
            static fn (RecurringTask $a, RecurringTask $b): int => [$a->urgencyAt($today)->rank(), $a->getDueOn() ?? new \DateTimeImmutable('9999-12-31')]
                <=> [$b->urgencyAt($today)->rank(), $b->getDueOn() ?? new \DateTimeImmutable('9999-12-31')],
        );

        return $this->render('task/index.html.twig', [
            'today' => $today,
            'tasks' => $tasks,
            'filterCategory' => $category,
            'onlyActive' => $onlyActive,
            'catalog' => $this->catalog->grouped(),
            'usedCatalogKeys' => array_keys($this->tasks->findByCatalogKeys()),
        ] + $this->formOptions());
    }

    #[Route('/terminy/novy', name: 'task_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request, 'task-new');

        if (!$this->hydrator->isValid($request)) {
            $this->addFlash('danger', 'Zadej název a kategorii termínu.');

            return $this->redirectToRoute('task_index');
        }

        $task = $this->hydrator->apply(new RecurringTask('', TaskCategory::REVISION, TaskRecurrence::INTERVAL), $request);
        $this->em->persist($task);
        $this->em->flush();
        $this->addFlash('success', 'Termín přidán.');

        return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
    }

    #[Route('/terminy/katalog', name: 'task_catalog_add', methods: ['POST'])]
    public function addFromCatalog(Request $request): Response
    {
        $this->assertCsrf($request, 'task-catalog');

        $keys = $request->request->all('keys');
        $existing = $this->tasks->findByCatalogKeys();
        $added = 0;
        foreach ($keys as $key) {
            $entry = $this->catalog->find((string) $key);
            if ($entry === null || isset($existing[$entry->key])) {
                continue;
            }
            $this->em->persist($this->catalog->toTask($entry));
            $added++;
        }
        $this->em->flush();

        $this->addFlash(
            $added > 0 ? 'success' : 'info',
            $added > 0
                ? sprintf('Přidáno %d termínů. Doplň u nich datum příštího termínu.', $added)
                : 'Nic nepřibylo — vybrané termíny už hlídáš.',
        );

        return $this->redirectToRoute('task_index');
    }

    #[Route('/terminy/{id}', name: 'task_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(RecurringTask $task): Response
    {
        return $this->render('task/show.html.twig', [
            'task' => $task,
            'today' => new \DateTimeImmutable('today'),
            'completions' => $task->getCompletions(),
        ] + $this->formOptions());
    }

    #[Route('/terminy/{id}/upravit', name: 'task_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(RecurringTask $task, Request $request): Response
    {
        $this->assertCsrf($request, 'task-edit-' . $task->getId());

        if (!$this->hydrator->isValid($request)) {
            $this->addFlash('danger', 'Zadej název a kategorii termínu.');

            return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
        }

        $this->hydrator->apply($task, $request);
        $this->em->flush();
        $this->addFlash('success', 'Termín upraven.');

        return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
    }

    #[Route('/terminy/{id}/smazat', name: 'task_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(RecurringTask $task, Request $request): Response
    {
        $this->assertCsrf($request, 'task-delete-' . $task->getId());

        foreach ($task->getCompletions() as $completion) {
            $this->attachments->delete($completion->getAttachmentPath());
        }
        $this->em->remove($task);
        $this->em->flush();
        $this->addFlash('success', 'Termín smazán i s historií.');

        return $this->redirectToRoute('task_index');
    }

    #[Route('/terminy/{id}/provedeno', name: 'task_complete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function complete(RecurringTask $task, Request $request): Response
    {
        $this->assertCsrf($request, 'task-complete-' . $task->getId());

        $completion = $this->recorder->record($task, $this->hydrator->completionInput($request));
        $this->em->flush();

        $next = $task->getDueOn();
        $this->addFlash('success', $next !== null
            ? sprintf('Zapsáno k %s. Příští termín: %s.', $completion->getDoneOn()->format('j. n. Y'), $next->format('j. n. Y'))
            : sprintf('Zapsáno k %s. Další termín se nehlídá.', $completion->getDoneOn()->format('j. n. Y')));

        return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
    }

    #[Route('/terminy/provedeni/{id}/smazat', name: 'task_completion_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteCompletion(TaskCompletion $completion, Request $request): Response
    {
        $this->assertCsrf($request, 'task-completion-delete-' . $completion->getId());

        $taskId = $completion->getTask()->getId();
        $this->recorder->delete($completion);
        $this->addFlash('success', 'Provedení smazáno, termín přepočítán.');

        return $this->redirectToRoute('task_show', ['id' => $taskId]);
    }

    #[Route('/terminy/provedeni/{id}/protokol', name: 'task_attachment_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function downloadAttachment(TaskCompletion $completion): BinaryFileResponse
    {
        $path = $completion->getAttachmentPath();
        if ($path === null || !$this->attachments->exists($path)) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($this->attachments->absolute($path));
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $completion->getAttachmentName() ?? basename($path),
        );

        return $response;
    }

    /** @return array<string, mixed> Číselníky pro formuláře termínu i provedení. */
    private function formOptions(): array
    {
        return [
            'categories' => TaskCategory::cases(),
            'recurrences' => TaskRecurrence::cases(),
            'units' => TaskIntervalUnit::cases(),
            'expenseCategories' => ExpenseCategory::cases(),
            'accounts' => $this->accounts->findOrdered(true),
        ];
    }
}
