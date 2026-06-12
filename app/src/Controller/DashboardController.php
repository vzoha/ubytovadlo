<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\VatPeriod;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Profit\YearEconomicsBuilder;
use App\Repository\AirbnbStatementRepository;
use App\Repository\BookingMonthlyInvoiceRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;
use App\Repository\VatPeriodRepository;
use App\Ubyport\UbyportQueue;
use App\Ubyport\UbyportRow;
use App\Vat\VatMonthCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    /** Kolik dní dopředu se dívat na pobyty pro úklid. */
    private const UPCOMING_DAYS = 30;

    /** Kolik měsíců zpět hlídat DPH (kromě běžícího aktuálního měsíce). */
    private const VAT_LOOKBACK_MONTHS = 6;

    /** Airbnb údaje hosta dostaneme až osobně na startu pobytu — připomínat dřív nemá smysl. */
    private const AIRBNB_DETAILS_LEAD_DAYS = 7;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly InvoiceRepository $invoices,
        private readonly BookingMonthlyInvoiceRepository $bookingInvoices,
        private readonly AirbnbStatementRepository $airbnbStatements,
        private readonly VatPeriodRepository $vatPeriods,
        private readonly VatMonthCalculator $vatCalculator,
        private readonly UbyportQueue $ubyportQueue,
        private readonly YearEconomicsBuilder $economicsBuilder,
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
            'vat' => $this->buildVat($today),
            'ubyport' => $this->buildUbyport($today),
            'economics' => $this->buildEconomics($today),
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
            $isOta = in_array($r->getChannel(), [Channel::BOOKING, Channel::AIRBNB], true);
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
     * @return array{
     *   current: array{year:int, month:int, base:float, vat:float, dueAt:\DateTimeImmutable, daysToDue:int},
     *   pending: list<array{
     *     year:int, month:int, base:float, vat:float,
     *     dueAt:\DateTimeImmutable, overdue:bool, daysToDue:int,
     *     missingBookingPdf:bool, missingAirbnbStatement:bool
     *   }>
     * }
     */
    private function buildVat(\DateTimeImmutable $today): array
    {
        $current = $this->vatMonthSummary((int) $today->format('Y'), (int) $today->format('n'), $today);
        $filedKeys = $this->vatPeriods->findFiledKeySet();
        $pending = [];

        $cursor = $today->modify('first day of previous month');
        for ($i = 0; $i < self::VAT_LOOKBACK_MONTHS; ++$i, $cursor = $cursor->modify('first day of previous month')) {
            if (isset($filedKeys[$cursor->format('Y-m')])) {
                continue;
            }

            $row = $this->vatMonthSummary((int) $cursor->format('Y'), (int) $cursor->format('n'), $today);
            // Měsíce, ve kterých nic neproběhlo (0 Kč) a nemají žádné podklady, neukazujeme.
            if ($row['base'] > 0.0 || $row['missingBookingPdf'] || $row['missingAirbnbStatement']) {
                $pending[] = $row;
            }
        }

        return ['current' => $current, 'pending' => $pending];
    }

    /**
     * @return array{
     *   year:int, month:int, base:float, vat:float,
     *   dueAt:\DateTimeImmutable, overdue:bool, daysToDue:int,
     *   missingBookingPdf:bool, missingAirbnbStatement:bool
     * }
     */
    private function vatMonthSummary(int $year, int $month, \DateTimeImmutable $today): array
    {
        $summary = $this->vatCalculator->summarize($year, $month);
        $dueAt = (new VatPeriod($year, $month))->getFilingDueAt();

        return [
            'year' => $year,
            'month' => $month,
            'base' => $summary->sumBaseCzk,
            'vat' => $summary->sumVatCzk,
            'dueAt' => $dueAt,
            'overdue' => $today > $dueAt,
            'daysToDue' => self::daysBetween($today, $dueAt),
            'missingBookingPdf' => $summary->hasBookingReservations
                && $this->bookingInvoices->findByPeriodMonth($year, $month) === null,
            'missingAirbnbStatement' => $summary->hasAirbnbReservations
                && $this->airbnbStatements->findAllByPeriodMonth($year, $month) === [],
        ];
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
