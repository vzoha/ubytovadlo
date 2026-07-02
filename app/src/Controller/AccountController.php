<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Cashflow\AccountBalanceCalculator;
use App\Cashflow\BalanceStatementReconciler;
use App\Entity\Account;
use App\Entity\BalanceStatement;
use App\Entity\LedgerEntry;
use App\Enum\AccountType;
use App\Enum\ExpenseCategory;
use App\Enum\LedgerEntryType;
use App\Repository\AccountRepository;
use App\Repository\BalanceStatementRepository;
use App\Repository\LedgerEntryRepository;
use App\Repository\ReservationIncomeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cashflow UI — účty, výdaje, převody mezi vlastními účty a uzávěrky
 * s dopočtem očekávaného stavu a srovnáním rozdílu korekcí.
 */
class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly LedgerEntryRepository $ledger,
        private readonly BalanceStatementRepository $statements,
        private readonly AccountBalanceCalculator $balances,
        private readonly BalanceStatementReconciler $reconciler,
        private readonly ReservationIncomeRepository $incomes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/ucty', name: 'account_index', methods: ['GET'])]
    public function index(): Response
    {
        $today = new \DateTimeImmutable('today');
        $accounts = $this->accounts->findOrdered();
        $cards = [];
        foreach ($accounts as $account) {
            $latest = $this->statements->findLatestForAccount($account);
            $cards[] = [
                'account' => $account,
                'balance' => $this->balances->balance($account, $today),
                'verifiedAt' => $latest?->getStatementDate(),
            ];
        }

        return $this->render('account/index.html.twig', [
            'cards' => $cards,
            'accounts' => $accounts,
            'recent' => \array_slice($this->ledger->findAllUpTo(), 0, 20),
            'incomes' => $this->incomes->findReceived($today, 20),
            'estimates' => $this->incomes->findExpected($today),
            'categories' => ExpenseCategory::cases(),
            'accountTypes' => AccountType::cases(),
        ]);
    }

    #[Route('/ucty/{id}', name: 'account_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Account $account): Response
    {
        $statements = [];
        foreach ($this->statements->findForAccount($account) as $statement) {
            $statements[] = ['statement' => $statement] + $this->reconciler->reconcile($statement);
        }

        return $this->render('account/show.html.twig', [
            'account' => $account,
            'balance' => $this->balances->balance($account, new \DateTimeImmutable('today')),
            'movements' => $this->ledger->findTouchingAccount($account),
            'statements' => $statements,
        ]);
    }

    #[Route('/ucty/vydaj', name: 'account_expense_add', methods: ['POST'])]
    public function addExpense(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('account-expense', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $account = $this->requireAccount($request->request->get('account'));
        $category = ExpenseCategory::tryFrom((string) $request->request->get('category', ''));
        $entry = new LedgerEntry(
            LedgerEntryType::EXPENSE,
            $this->parseDate($request->request->get('occurred_on')),
            $this->parseAmount($request->request->get('amount')),
            $account,
        );
        $entry->setCategory($category ?? ExpenseCategory::OTHER);
        $entry->setNote($this->parseNote($request->request->get('note')));
        $this->em->persist($entry);
        $this->em->flush();
        $this->addFlash('success', 'Výdaj zapsán.');

        return $this->redirectToRoute('account_index');
    }

    #[Route('/ucty/prevod', name: 'account_transfer_add', methods: ['POST'])]
    public function addTransfer(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('account-transfer', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $from = $this->requireAccount($request->request->get('account'));
        $to = $this->requireAccount($request->request->get('counter_account'));
        if ($from->getId() === $to->getId()) {
            $this->addFlash('danger', 'Převod musí být mezi dvěma různými účty.');

            return $this->redirectToRoute('account_index');
        }

        $entry = new LedgerEntry(
            LedgerEntryType::TRANSFER,
            $this->parseDate($request->request->get('occurred_on')),
            $this->parseAmount($request->request->get('amount')),
            $from,
        );
        $entry->setCounterAccount($to);
        $entry->setNote($this->parseNote($request->request->get('note')));
        $this->em->persist($entry);
        $this->em->flush();
        $this->addFlash('success', 'Převod zapsán.');

        return $this->redirectToRoute('account_index');
    }

    #[Route('/ucty/uzaverka', name: 'account_statement_add', methods: ['POST'])]
    public function addStatement(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('account-statement', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $account = $this->requireAccount($request->request->get('account'));
        $statement = new BalanceStatement(
            $account,
            $this->parseDate($request->request->get('statement_date')),
            $this->parseAmount($request->request->get('actual_balance')),
        );
        $statement->setNote($this->parseNote($request->request->get('note')));
        $this->em->persist($statement);
        $this->em->flush();

        $difference = $this->reconciler->reconcile($statement)['difference'];
        $this->addFlash('success', sprintf('Uzávěrka uložena. Rozdíl oproti očekávanému stavu: %s Kč.', number_format($difference, 0, ',', ' ')));

        return $this->redirectToRoute('account_show', ['id' => $account->getId()]);
    }

    #[Route('/ucty/uzaverka/{id}/korekce', name: 'account_statement_correction', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createCorrection(BalanceStatement $statement, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('account-correction-' . $statement->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entry = $this->reconciler->createCorrection($statement);
        $this->addFlash(
            $entry !== null ? 'success' : 'info',
            $entry !== null ? 'Korekce založena, stav účtu srovnán.' : 'Není co srovnávat — stav sedí.',
        );

        return $this->redirectToRoute('account_show', ['id' => $statement->getAccount()->getId()]);
    }

    #[Route('/ucty/novy', name: 'account_add', methods: ['POST'])]
    public function addAccount(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('account-new', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = trim((string) $request->request->get('name'));
        $type = AccountType::tryFrom((string) $request->request->get('type', ''));
        if ($name === '' || $type === null) {
            $this->addFlash('danger', 'Zadej název a typ účtu.');

            return $this->redirectToRoute('account_index');
        }

        $account = new Account($name, $type, $this->parseAmount($request->request->get('opening_balance')));
        $account->setSortOrder(\count($this->accounts->findOrdered()));
        $this->em->persist($account);
        $this->em->flush();
        $this->addFlash('success', 'Účet přidán.');

        return $this->redirectToRoute('account_index');
    }

    #[Route('/ucty/vydaj/{id}/smazat', name: 'account_expense_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteEntry(LedgerEntry $entry, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('account-entry-delete-' . $entry->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->em->remove($entry);
        $this->em->flush();
        $this->addFlash('success', 'Pohyb smazán.');

        return $this->redirectToRoute('account_index');
    }

    private function requireAccount(mixed $id): Account
    {
        $account = \is_numeric($id) ? $this->accounts->find((int) $id) : null;
        if ($account === null) {
            throw $this->createNotFoundException('Účet nenalezen.');
        }

        return $account;
    }

    private function parseDate(mixed $value): \DateTimeImmutable
    {
        $raw = trim((string) $value);

        return $raw !== '' ? new \DateTimeImmutable($raw) : new \DateTimeImmutable('today');
    }

    private function parseAmount(mixed $value): int
    {
        return (int) round((float) str_replace([' ', ','], ['', '.'], (string) $value));
    }

    private function parseNote(mixed $value): ?string
    {
        $note = trim((string) $value);

        return $note !== '' ? $note : null;
    }
}
