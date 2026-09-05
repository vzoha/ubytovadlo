<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Reservation;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Occupancy\OccupancyConflictFinder;
use App\Profit\YearEconomicsBuilder;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;
use App\Setup\SetupChecklist;
use App\Task\TaskOverview;
use App\Timeline\PendingMessageOverview;
use App\Ubyport\UbyportQueue;
use App\Ubyport\UbyportRow;
use App\Vat\VatDashboardSummary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    /** Kolik dní dopředu se dívat na pobyty pro úklid. */
    private const UPCOMING_DAYS = 30;

    /** Kolik měsíců zpět hlídat DPH (kromě běžícího aktuálního měsíce). */

    /** Airbnb údaje hosta dostaneme až osobně na startu pobytu — připomínat dřív nemá smysl. */
    private const AIRBNB_DETAILS_LEAD_DAYS = 7;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly InvoiceRepository $invoices,
        private readonly VatDashboardSummary $vatSummary,
        private readonly UbyportQueue $ubyportQueue,
        private readonly YearEconomicsBuilder $economicsBuilder,
        private readonly SetupChecklist $setupChecklist,
        private readonly OccupancyConflictFinder $occupancyFinder,
        private readonly TaskOverview $taskOverview,
        private readonly PendingMessageOverview $pendingMessages,
    ) {
    }

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $today = new \DateTimeImmutable('today');

        return $this->render('dashboard/index.html.twig', [
            'today' => $today,
            'upcoming' => $this->buildUpcoming($today),
            'needsDetails' => $this->buildNeedsDetails($today),
            'missingInvoices' => $this->buildMissingInvoices($today),
            'vat' => $this->vatSummary->build($today),
            'ubyport' => $this->buildUbyport($today),
            'economics' => $this->buildEconomics($today),
            'setupPending' => $this->setupChecklist->pending(),
            'setupDismissedCount' => $this->setupChecklist->dismissedCount(),
            'occupancyConflicts' => $this->occupancyFinder->find($this->reservations->findActiveForOccupancy($today)),
            'tasks' => $this->taskOverview->summary($today),
            'pendingMessages' => $this->pendingMessages->due($today),
        ]);
    }

    /**
     * Souhrn ekonomiky aktuálního roku pro kartu na dashboardu —
     * uskutečněné pobyty zvlášť, budoucí jen jako výhled.
     *
     * @return array{
     *     year: int,
     *     realized: array{count: int, nights: int, income: string, expenses: string, profit: string, hasEstimates: bool},
     *     expected: array{count: int, nights: int, income: string, expenses: string, profit: string, hasEstimates: bool}
     * }
     */
    private function buildEconomics(\DateTimeImmutable $today): array
    {
        $year = (int) $today->format('Y');
        $economics = $this->economicsBuilder->build($year, $today);

        return [
            'year' => $year,
            'realized' => $economics['realized'],
            'expected' => $economics['expected'],
        ];
    }

    /**
     * @return list<array{reservation: Reservation, daysUntil: int}>
     */
    private function buildNeedsDetails(\DateTimeImmutable $today): array
    {
        $rows = [];
        foreach ($this->reservations->findNeedsDetails() as $r) {
            $daysUntil = self::daysBetween($today, $r->getCheckIn());
            if ($r->getChannel() === Channel::AIRBNB && $daysUntil > self::AIRBNB_DETAILS_LEAD_DAYS) {
                continue;
            }
            $rows[] = [
                'reservation' => $r,
                'daysUntil' => $daysUntil,
            ];
        }

        return $rows;
    }

    /**
     * Ubyport widget: počty k nahlášení / neúplných + kolik z nich je po
     * zákonné lhůtě (příjezd + 3 prac. dny) — řídí eskalaci barvy na dashboardu.
     *
     * @return array{toReport:int, incomplete:int, overdue:int}
     */
    private function buildUbyport(\DateTimeImmutable $today): array
    {
        $toReport = 0;
        $incomplete = 0;
        $overdue = 0;
        foreach ($this->ubyportQueue->rows($today) as $row) {
            if ($row->state === UbyportRow::STATE_TO_REPORT) {
                $toReport++;
            } elseif ($row->state === UbyportRow::STATE_INCOMPLETE) {
                $incomplete++;
            }
            if ($row->isOverdue()) {
                $overdue++;
            }
        }

        return [
            'toReport' => $toReport,
            'incomplete' => $incomplete,
            'overdue' => $overdue,
        ];
    }

    /**
     * Pobyty pro úklid: den odjezdu (datum = checkOut == dnes, úklid je dnes),
     * probíhající (datum = checkOut > dnes) nebo nadcházející (datum = checkIn).
     *
     * @return list<array{
     *   reservation: Reservation,
     *   kind: 'departure'|'in_progress'|'arrival',
     *   daysUntil: int,
     *   date: \DateTimeImmutable
     * }>
     */
    private function buildUpcoming(\DateTimeImmutable $today): array
    {
        $horizon = $today->modify('+' . self::UPCOMING_DAYS . ' days');
        $todayYmd = $today->format('Y-m-d');
        $rows = [];

        foreach ($this->reservations->findUpcoming($today, $horizon) as $r) {
            $checkIn = $r->getCheckIn();
            $checkOut = $r->getCheckOut();

            if ($checkOut !== null && $checkOut->format('Y-m-d') === $todayYmd) {
                $kind = 'departure';
                $date = $checkOut;
            } elseif ($checkOut !== null && $checkIn <= $today && $checkOut > $today) {
                $kind = 'in_progress';
                $date = $checkOut;
            } else {
                $kind = 'arrival';
                $date = $checkIn;
            }

            $rows[] = [
                'reservation' => $r,
                'kind' => $kind,
                'date' => $date,
                'daysUntil' => self::daysBetween($today, $date),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $rows;
    }

    /** Kolik dní dopředu zobrazovat rezervace, pro které je vhodný čas vystavit fakturu. */
    private const INVOICE_HORIZON_DAYS = 7;

    /**
     * Rezervace bez vystavené faktury (FULL/FINAL), kde se fakturu už hodí vystavit:
     * check_in ≤ dnes + 7 dní. Bez dolního ohraničení — nezaplacené dluhy ze starších
     * měsíců se vyplaceně mají držet viditelné napořád.
     *
     * @return list<array{
     *   reservation: Reservation,
     *   missing: 'final'|'full'|'mode_unset'|'ota_during_stay',
     *   daysSinceCheckout: int
     * }>
     */
    private function buildMissingInvoices(\DateTimeImmutable $today): array
    {
        $horizon = $today->modify('+' . self::INVOICE_HORIZON_DAYS . ' days');
        $candidates = $this->reservations->findInvoiceCandidatesUpToCheckIn($horizon);
        if ($candidates === []) {
            return [];
        }

        $ids = array_map(static fn (Reservation $r): int => (int) $r->getId(), $candidates);
        $haveFinal = array_flip($this->invoices->findReservationIdsWithInvoiceOfType(
            $ids,
            [InvoiceType::FINAL, InvoiceType::FULL],
        ));

        $rows = [];
        foreach ($candidates as $r) {
            if (isset($haveFinal[(int) $r->getId()])) {
                continue;
            }
            $mode = $r->getBillingMode();
            if ($mode !== null && !$mode->isInvoiced()) {
                continue;
            }
            $isOta = $r->getChannel()->isOta();
            $checkOut = $r->getCheckOut() ?? $r->getCheckIn();
            $rows[] = [
                'reservation' => $r,
                'missing' => $isOta ? 'ota_during_stay' : $this->missingInvoiceKind($mode),
                'daysSinceCheckout' => self::daysBetween($checkOut, $today),
            ];
        }

        return $rows;
    }

    private function missingInvoiceKind(?BillingMode $mode): string
    {
        if ($mode === null) {
            return 'mode_unset';
        }

        return $mode === BillingMode::STANDARD_WITH_DEPOSIT ? 'final' : 'full';
    }

    /**
     * Počet dní mezi dvěma daty, znaménkový (záporné = $to v minulosti vůči $from).
     * Null $to vrátí 0 — vhodné pro rezervace bez nastaveného check-outu.
     */
    private static function daysBetween(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int
    {
        if ($from === null || $to === null) {
            return 0;
        }

        return (int) $from->diff($to)->format('%r%a');
    }
}
