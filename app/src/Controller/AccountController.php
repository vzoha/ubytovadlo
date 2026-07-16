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
use App\Cashflow\CashflowSummary;
use App\Controller\Concern\ChecksCsrf;
use App\Entity\Account;
use App\Entity\BalanceStatement;
use App\Entity\LedgerEntry;
use App\Enum\AccountType;
use App\Enum\ExpenseCategory;
use App\Enum\ExpenseGroup;
use App\Enum\LedgerEntryType;
use App\Formatting\CzechCalendar;
use App\Repository\AccountRepository;
use App\Repository\BalanceStatementRepository;
use App\Repository\LedgerEntryRepository;
use App\Repository\ReservationReceiptRepository;
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
    use ChecksCsrf;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly LedgerEntryRepository $ledger,
        private readonly BalanceStatementRepository $statements,
        private readonly AccountBalanceCalculator $balances,
        private readonly BalanceStatementReconciler $reconciler,
        private readonly ReservationReceiptRepository $receipts,
        private readonly EntityManagerInterface $em,
    ) {
    }

    private const PER_PAGE = 20;

    /** Strop řádků CSV exportu — pojistka proti vyčerpání paměti na velké historii. */
    private const CSV_MAX_ROWS = 100000;

    #[Route('/ucty', name: 'account_index', methods: ['GET'])]
    public function index(Request $request): Response
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

        $filter = $this->readFilter($request);
        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->ledger->countFiltered($filter['account'], $filter['type'], $filter['from'], $filter['to']);
        $movements = $this->ledger->findFiltered(
            $filter['account'],
            $filter['type'],
            $filter['from'],
            $filter['to'],
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE,
        );

        $incomePage = max(1, $request->query->getInt('rpage', 1));
        $incomeTotal = $this->receipts->countReceived($today);

        return $this->render('account/index.html.twig', [
            'cards' => $cards,
            'accounts' => $accounts,
            'recent' => $movements,
            'incomes' => $this->receipts->findReceived($today, self::PER_PAGE, ($incomePage - 1) * self::PER_PAGE),
            'estimates' => $this->receipts->findExpected($today),
            'categories' => ExpenseCategory::cases(),
            'expenseGroups' => ExpenseGroup::cases(),
            'accountTypes' => AccountType::cases(),
            'entryTypes' => LedgerEntryType::cases(),
            'filter' => $filter,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
            'incomePage' => $incomePage,
            'incomePages' => max(1, (int) ceil($incomeTotal / self::PER_PAGE)),
            'incomeTotal' => $incomeTotal,
        ]);
    }

    #[Route('/ucty/souhrn/{year}', name: 'account_summary', methods: ['GET'], requirements: ['year' => '\d{4}'], defaults: ['year' => null])]
    public function summary(?int $year, CashflowSummary $summary): Response
    {
        $year ??= (int) (new \DateTimeImmutable('today'))->format('Y');

        return $this->render('account/summary.html.twig', [
            'year' => $year,
            'summary' => $summary->forYear($year),
            'months' => CzechCalendar::names(),
        ]);
    }

    #[Route('/ucty/export.csv', name: 'account_export_csv', methods: ['GET'])]
    public function exportCsv(Request $request): Response
    {
        $filter = $this->readFilter($request);
        $movements = $this->ledger->findFiltered($filter['account'], $filter['type'], $filter['from'], $filter['to'], self::CSV_MAX_ROWS, 0);

        $rows = [['datum', 'typ', 'ucet', 'protistrana', 'kategorie', 'castka_czk', 'poznamka']];
        foreach ($movements as $m) {
            $rows[] = [
                $m->getOccurredOn()->format('Y-m-d'),
                $m->getType()->label(),
                $m->getAccount()->getName(),
                $m->getCounterAccount()?->getName() ?? '',
                $m->getCategory()?->label() ?? '',
                (string) $m->getAmountCzk(),
                $m->getNote() ?? '',
            ];
        }

        $csv = "\u{FEFF}" . implode("\n", array_map(
            static fn (array $row): string => implode(';', array_map(
                static fn (string $cell): string => '"' . str_replace('"', '""', $cell) . '"',
                $row,
            )),
            $rows,
        ));

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="cashflow.csv"',
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
            'accounts' => $this->accounts->findOrdered(),
            'categories' => ExpenseCategory::cases(),
            'expenseGroups' => ExpenseGroup::cases(),
            'accountTypes' => AccountType::cases(),
        ]);
    }

    #[Route('/ucty/vydaj', name: 'account_expense_add', methods: ['POST'])]
    public function addExpense(Request $request): Response
    {
        $this->assertCsrf($request, 'account-expense');

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
        $this->warnIfOutsideWindow($entry->getOccurredOn(), $account);

        return $this->redirectToRoute('account_index');
    }

    #[Route('/ucty/prijem', name: 'account_income_add', methods: ['POST'])]
    public function addIncome(Request $request): Response
    {
        $this->assertCsrf($request, 'account-income');

        $account = $this->requireAccount($request->request->get('account'));
        $entry = new LedgerEntry(
            LedgerEntryType::INCOME,
            $this->parseDate($request->request->get('occurred_on')),
            $this->parseAmount($request->request->get('amount')),
            $account,
        );
        $entry->setNote($this->parseNote($request->request->get('note')));
        $this->em->persist($entry);
        $this->em->flush();
        $this->addFlash('success', 'Příjem zapsán.');
        $this->warnIfOutsideWindow($entry->getOccurredOn(), $account);

        return $this->redirectToRoute('account_index');
    }

    #[Route('/ucty/prevod', name: 'account_transfer_add', methods: ['POST'])]
    public function addTransfer(Request $request): Response
    {
        $this->assertCsrf($request, 'account-transfer');

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
        $this->warnIfOutsideWindow($entry->getOccurredOn(), $from, $to);

        return $this->redirectToRoute('account_index');
    }

    #[Route('/ucty/uzaverka', name: 'account_statement_add', methods: ['POST'])]
    public function addStatement(Request $request): Response
    {
        $this->assertCsrf($request, 'account-statement');

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
        $this->assertCsrf($request, 'account-correction-' . $statement->getId());

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
        $this->assertCsrf($request, 'account-new');

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
        $this->assertCsrf($request, 'account-entry-delete-' . $entry->getId());

        $this->em->remove($entry);
        $this->em->flush();
        $this->addFlash('success', 'Pohyb smazán.');

        return $this->redirectToRoute('account_index');
    }

    #[Route('/ucty/pohyb/{id}/upravit', name: 'account_entry_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editEntry(LedgerEntry $entry, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'account-entry-edit-' . $entry->getId());

            $entry->setOccurredOn($this->parseDate($request->request->get('occurred_on')));
            $entry->setAmountCzk($this->parseAmount($request->request->get('amount')));
            $entry->setNote($this->parseNote($request->request->get('note')));
            $entry->setAccount($this->requireAccount($request->request->get('account')));
            if ($entry->getType() === LedgerEntryType::EXPENSE) {
                $entry->setCategory(ExpenseCategory::tryFrom((string) $request->request->get('category', '')) ?? ExpenseCategory::OTHER);
            }
            if ($entry->getType() === LedgerEntryType::TRANSFER) {
                $to = $this->requireAccount($request->request->get('counter_account'));
                if ($to->getId() === $entry->getAccount()->getId()) {
                    $this->addFlash('danger', 'Převod musí být mezi dvěma různými účty.');

                    return $this->redirectToRoute('account_entry_edit', ['id' => $entry->getId()]);
                }
                $entry->setCounterAccount($to);
            }
            $this->em->flush();
            $this->addFlash('success', 'Pohyb upraven.');
            $this->warnIfOutsideWindow($entry->getOccurredOn(), $entry->getAccount(), ...array_filter([$entry->getCounterAccount()]));

            return $this->redirectToRoute('account_index');
        }

        return $this->render('account/edit_entry.html.twig', [
            'entry' => $entry,
            'accounts' => $this->accounts->findOrdered(),
            'categories' => ExpenseCategory::cases(),
            'expenseGroups' => ExpenseGroup::cases(),
        ]);
    }

    #[Route('/ucty/{id}/upravit', name: 'account_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editAccount(Account $account, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'account-edit-' . $account->getId());

            $name = trim((string) $request->request->get('name'));
            $type = AccountType::tryFrom((string) $request->request->get('type', ''));
            if ($name === '' || $type === null) {
                $this->addFlash('danger', 'Zadej název a typ účtu.');

                return $this->redirectToRoute('account_edit', ['id' => $account->getId()]);
            }

            $account->setName($name);
            $account->setType($type);
            $account->setOpeningBalanceCzk($this->parseAmount($request->request->get('opening_balance')));
            $account->setOpeningDate($this->parseDate($request->request->get('opening_date')));
            $account->setActive($request->request->getBoolean('active'));
            $account->setNote($this->parseNote($request->request->get('note')));
            $this->em->flush();
            $this->addFlash('success', 'Účet upraven.');

            return $this->redirectToRoute('account_show', ['id' => $account->getId()]);
        }

        return $this->render('account/edit_account.html.twig', [
            'account' => $account,
            'accountTypes' => AccountType::cases(),
        ]);
    }

    /**
     * @return array{account: ?Account, type: ?LedgerEntryType, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable}
     */
    private function readFilter(Request $request): array
    {
        $accountId = $request->query->get('account');
        $from = trim((string) $request->query->get('from'));
        $to = trim((string) $request->query->get('to'));

        return [
            'account' => \is_numeric($accountId) ? $this->accounts->find((int) $accountId) : null,
            'type' => LedgerEntryType::tryFrom((string) $request->query->get('type', '')),
            'from' => $this->parseDateOrNull($from),
            'to' => $this->parseDateOrNull($to),
        ];
    }

    /** Datum z uživatelského vstupu; nevalidní (např. `?from=2026-99-99`) → null místo 500. */
    private function parseDateOrNull(string $raw): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Stav účtu počítá jen pohyby v okně [openingDate účtu, dnes]. Pohyb datovaný
     * mimo okno se do stavu nezapočítá — upozorni, ať uživatel netápe, proč se
     * zůstatek po zápisu nezměnil.
     */
    private function warnIfOutsideWindow(\DateTimeImmutable $occurredOn, Account ...$accounts): void
    {
        foreach ($accounts as $account) {
            if ($occurredOn < $account->getOpeningDate()) {
                $this->addFlash('warning', sprintf(
                    'Datum je před založením účtu „%s" (%s) — pohyb se do jeho stavu nezapočítá. Uprav datum pohybu nebo počáteční datum účtu.',
                    $account->getName(),
                    $account->getOpeningDate()->format('j. n. Y'),
                ));
            }
        }

        if ($occurredOn > new \DateTimeImmutable('today')) {
            $this->addFlash('warning', 'Datum je v budoucnosti — pohyb se do stavu účtu započítá, až daný den nastane.');
        }
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
        return $this->parseDateOrNull(trim((string) $value)) ?? new \DateTimeImmutable('today');
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
