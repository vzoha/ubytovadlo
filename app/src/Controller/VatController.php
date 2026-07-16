<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AirbnbStatement;
use App\Entity\BookingMonthlyInvoice;
use App\Entity\Reservation;
use App\Entity\VatPeriod;
use App\Enum\Channel;
use App\Form\AirbnbStatementUploadType;
use App\Formatting\Money;
use App\Invoice\TaxProfileConfig;
use App\Repository\AirbnbStatementRepository;
use App\Repository\BookingMonthlyInvoiceRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;
use App\Repository\VatPeriodRepository;
use App\Storage\PdfStorage;
use App\Vat\VatMonthCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class VatController extends AbstractController
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly BookingMonthlyInvoiceRepository $invoices,
        private readonly AirbnbStatementRepository $airbnbStatements,
        private readonly VatPeriodRepository $periods,
        private readonly VatMonthCalculator $calculator,
        private readonly InvoiceRepository $hostInvoices,
        private readonly TaxProfileConfig $taxProfile,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
        private readonly PdfStorage $pdfStorage,
    ) {
    }

    #[Route('/dph', name: 'vat_list', methods: ['GET'])]
    public function list(): Response
    {
        // Sestavíme měsíce, ve kterých máme aspoň jednu rezervaci s odjezdem
        // a vyplněnou provizí, NEBO Booking měsíční fakturu.
        $months = [];

        foreach ($this->reservations->findAllWithCommission() as $r) {
            $key = $r->getCheckOut()?->format('Y-m');
            if ($key !== null) {
                $months[$key] = true;
            }
        }
        foreach ($this->invoices->findAll() as $inv) {
            $months[$inv->getPeriodTo()->format('Y-m')] = true;
        }
        // U plátce i měsíce, kde jsou jen výstupní faktury hostům (bez OTA provize).
        if ($this->taxProfile->current()->chargesOutputVat()) {
            foreach ($this->hostInvoices->findMonthsWithOutputVat() as $ym) {
                $months[$ym] = true;
            }
        }

        krsort($months);

        $rows = [];
        foreach (array_keys($months) as $key) {
            [$y, $m] = array_map('intval', explode('-', $key));
            $rows[] = $this->buildMonthSummary($y, $m);
        }

        return $this->render('vat/list.html.twig', [
            'months' => $rows,
            'taxProfile' => $this->taxProfile->current(),
        ]);
    }

    #[Route('/dph/{year}-{month}', name: 'vat_detail', methods: ['GET'], requirements: ['year' => '\d{4}', 'month' => '\d{2}'])]
    public function detail(int $year, int $month): Response
    {
        return $this->render('vat/detail.html.twig', [
            'summary' => $this->buildMonthSummary($year, $month),
            'taxProfile' => $this->taxProfile->current(),
        ]);
    }

    #[Route('/dph/{year}-{month}/podklad.csv', name: 'vat_csv', methods: ['GET'], requirements: ['year' => '\d{4}', 'month' => '\d{2}'])]
    public function csv(int $year, int $month): StreamedResponse
    {
        $summary = $this->calculator->summarize($year, $month);
        $hostInvoices = $this->hostInvoices->findIssuedInMonth($year, $month);

        $response = new StreamedResponse(function () use ($summary, $hostInvoices): void {
            $out = fopen('php://output', 'wb');
            \assert($out !== false);
            // UTF-8 BOM — Excel jinak háže diakritiku.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Typ', 'Doklad', 'Datum', 'Partner', 'Základ Kč', 'DPH Kč', 'Sazba %'], ';', '"', '');
            foreach ($hostInvoices as $inv) {
                if ($inv->getVatAmountTotal() === null) {
                    continue;
                }
                fputcsv($out, [
                    'Výstup — faktura hostovi',
                    $inv->getNumber(),
                    $inv->getIssuedAt()->format('Y-m-d'),
                    $inv->getCustomerName(),
                    $inv->getVatBaseTotal(),
                    $inv->getVatAmountTotal(),
                    '12',
                ], ';', '"', '');
            }
            foreach ($summary->reservations as $r) {
                if ($r->getVatBaseCzk() === null) {
                    continue;
                }
                fputcsv($out, [
                    'Vstup — provize ' . $r->getChannel()->label(),
                    $r->getExternalId() ?? ('rezervace ' . $r->getId()),
                    $r->getVatDuzp()?->format('Y-m-d') ?? '',
                    $r->getGuestName() ?? '',
                    $r->getVatBaseCzk(),
                    $r->getVatAmountCzk() ?? '',
                    '21',
                ], ';', '"', '');
            }
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="dph-podklad-%04d-%02d.csv"', $year, $month));

        return $response;
    }

    #[Route('/dph/{year}-{month}/airbnb-statement', name: 'vat_airbnb_upload', methods: ['GET', 'POST'], requirements: ['year' => '\d{4}', 'month' => '\d{2}'])]
    public function airbnbStatementUpload(int $year, int $month, Request $request): Response
    {
        $defaultFrom = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

        // Předvyplnění z rezervace (?reservation=ID) — Airbnb posílá doklad per rezervaci,
        // takže termíny i provizi známe z DB a majitelka jen přetáhne PDF.
        $prefill = [
            'periodFrom' => $defaultFrom,
            'periodTo' => $defaultFrom->modify('last day of this month'),
            'commissionCzk' => 0.0,
            'notes' => null,
            'reservationId' => null,
        ];
        $airbnbStayCode = null;
        $reservationId = $request->query->getInt('reservation');
        if ($reservationId > 0 && !$request->isMethod('POST')) {
            $reservation = $this->reservations->find($reservationId);
            if ($reservation !== null && $reservation->getCheckOut() !== null) {
                $prefill['periodFrom'] = $reservation->getCheckIn();
                $prefill['periodTo'] = $reservation->getCheckOut();
                $prefill['commissionCzk'] = (float) ($reservation->getCommissionAmount() ?? 0);
                $prefill['notes'] = trim(sprintf('%s · %s', $reservation->getGuestName() ?? '', $reservation->getExternalId() ?? ''), ' ·');
                $prefill['reservationId'] = (string) $reservationId;
                $airbnbStayCode = $reservation->getExternalId();
            }
        }

        $form = $this->createForm(AirbnbStatementUploadType::class, $prefill);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            /** @var UploadedFile|null $pdfFile */
            $pdfFile = $form->get('pdf')->getData();

            if ($pdfFile === null) {
                $this->addFlash('warning', 'Nahraj prosím PDF receipt.');
            } else {
                $statement = new AirbnbStatement(
                    $data['periodFrom'],
                    $data['periodTo'],
                    Money::normalize((float) $data['commissionCzk']),
                    $this->moveAirbnbStatementPdf($pdfFile, $year, $month),
                );
                $statement->setNotes($data['notes'] ?? null);

                $submittedReservationId = (int) ($data['reservationId'] ?? 0);
                if ($submittedReservationId > 0) {
                    $statement->setReservation($this->reservations->find($submittedReservationId));
                }

                $this->em->persist($statement);
                $this->em->flush();
                $this->addFlash('success', 'Airbnb receipt přidán.');

                return $this->redirectToRoute('vat_detail', ['year' => $year, 'month' => sprintf('%02d', $month)]);
            }
        }

        return $this->render('vat/airbnb_statement_upload.html.twig', [
            'year' => $year,
            'month' => $month,
            'form' => $form,
            'existing' => $this->airbnbStatements->findAllByPeriodMonth($year, $month),
            'airbnbStayCode' => $airbnbStayCode,
        ]);
    }

    #[Route('/dph/airbnb-statement/{id}/delete', name: 'vat_airbnb_statement_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function airbnbStatementDelete(AirbnbStatement $statement, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('vat-airbnb-delete-' . $statement->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $year = (int) $statement->getPeriodTo()->format('Y');
        $month = (int) $statement->getPeriodTo()->format('m');

        $pdfPath = $this->pdfStorage->absolute($statement->getPdfPath());
        $this->em->remove($statement);
        $this->em->flush();

        if ($pdfPath !== '' && is_file($pdfPath) && str_starts_with($pdfPath, $this->projectDir . '/var/')) {
            @unlink($pdfPath);
        }

        $this->addFlash('success', 'Airbnb receipt smazán.');

        return $this->redirectToRoute('vat_detail', ['year' => $year, 'month' => sprintf('%02d', $month)]);
    }

    private function moveAirbnbStatementPdf(UploadedFile $file, int $year, int $month): string
    {
        $dir = $this->projectDir . '/var/invoices/airbnb';
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create directory %s', $dir));
        }
        $filename = sprintf('airbnb-%04d-%02d-%s.pdf', $year, $month, bin2hex(random_bytes(4)));
        $file->move($dir, $filename);

        return $this->pdfStorage->relative($dir . '/' . $filename);
    }

    #[Route('/dph/booking-invoice/{id}/pdf', name: 'vat_booking_invoice_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function bookingInvoicePdf(BookingMonthlyInvoice $invoice): BinaryFileResponse
    {
        return $this->streamPdf($invoice->getPdfPath(), sprintf('booking-invoice-%s.pdf', $invoice->getInvoiceNumber()));
    }

    #[Route('/dph/airbnb-statement/{id}/pdf', name: 'vat_airbnb_statement_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function airbnbStatementPdf(AirbnbStatement $statement): BinaryFileResponse
    {
        return $this->streamPdf(
            $statement->getPdfPath(),
            sprintf('airbnb-%s.pdf', $statement->getPeriodTo()->format('Y-m')),
        );
    }

    private function streamPdf(string $storedPath, string $downloadName): BinaryFileResponse
    {
        $path = $this->pdfStorage->absolute($storedPath);
        if (!is_file($path)) {
            throw $this->createNotFoundException('PDF nenalezeno na disku.');
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $downloadName);

        return $response;
    }

    #[Route('/dph/{year}-{month}/mark-filed', name: 'vat_mark_filed', methods: ['POST'], requirements: ['year' => '\d{4}', 'month' => '\d{2}'])]
    public function markFiled(int $year, int $month, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('vat-file-' . $year . '-' . $month, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $filedAtInput = trim((string) $request->request->get('filed_at', ''));
        $paidAtInput = trim((string) $request->request->get('paid_at', ''));
        $paidAmountInput = trim((string) $request->request->get('paid_amount_czk', ''));

        try {
            $filedAt = $filedAtInput !== ''
                ? new \DateTimeImmutable($filedAtInput)
                : new \DateTimeImmutable();
            $paidAt = $paidAtInput !== '' ? new \DateTimeImmutable($paidAtInput) : null;
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Neplatné datum: ' . $e->getMessage());

            return $this->redirectToRoute('vat_detail', ['year' => $year, 'month' => sprintf('%02d', $month)]);
        }

        $paidAmount = null;
        if ($paidAmountInput !== '') {
            $paidAmount = Money::parse($paidAmountInput);
            if ($paidAmount === null) {
                $this->addFlash('danger', 'Neplatná částka úhrady.');

                return $this->redirectToRoute('vat_detail', ['year' => $year, 'month' => sprintf('%02d', $month)]);
            }
        }

        $summary = $this->calculator->summarize($year, $month);
        $liability = $summary->liability($this->taxProfile->current());
        $period = $this->periods->findOrCreate($year, $month);
        $period->setSumBaseCzk(Money::normalize($summary->sumBaseCzk));
        $period->setSumVatCzk(Money::normalize($summary->sumVatCzk));
        $period->setOutputVatCzk($this->taxProfile->current()->chargesOutputVat() ? Money::normalize($liability->outputVatCzk) : null);
        $period->setInputDeductibleCzk($this->taxProfile->current()->chargesOutputVat() ? Money::normalize($liability->deductibleVatCzk) : null);
        $period->setFiledAt($filedAt);
        $period->setPaidAt($paidAt);
        $period->setPaidAmountCzk($paidAmount);

        $this->em->flush();
        $this->addFlash('success', sprintf('Měsíc %04d-%02d uložen.', $year, $month));

        return $this->redirectToRoute('vat_detail', ['year' => $year, 'month' => sprintf('%02d', $month)]);
    }

    /**
     * @return array{
     *     year: int, month: int,
     *     reservations: list<Reservation>,
     *     invoice: BookingMonthlyInvoice|null,
     *     airbnbStatements: list<AirbnbStatement>,
     *     airbnbStatementSum: float,
     *     airbnbReservations: list<Reservation>,
     *     airbnbStatementByReservation: array<int, AirbnbStatement>,
     *     airbnbUnlinkedStatements: list<AirbnbStatement>,
     *     airbnbDelta: float|null,
     *     sumBaseCzk: float, sumVatCzk: float,
     *     bookingReservationSum: float, bookingDelta: float|null,
     *     period: VatPeriod|null,
     *     liability: \App\Vat\VatLiability,
     *     outputBaseCzk: float, outputVatCzk: float,
     * }
     */
    private function buildMonthSummary(int $year, int $month): array
    {
        $summary = $this->calculator->summarize($year, $month);
        $invoice = $this->invoices->findByPeriodMonth($year, $month);
        $airbnbStatements = $this->airbnbStatements->findAllByPeriodMonth($year, $month);
        $period = $this->periods->findOneBy(['year' => $year, 'month' => $month]);

        $bookingDelta = $invoice !== null
            ? round($summary->bookingReservationSum - (float) $invoice->getTotalPayable(), 2)
            : null;

        $airbnbStatementSum = 0.0;
        $statementByReservation = [];
        $unlinkedStatements = [];
        foreach ($airbnbStatements as $s) {
            $airbnbStatementSum += (float) $s->getCommissionCzk();
            $linked = $s->getReservation();
            if ($linked !== null) {
                $statementByReservation[$linked->getId()] = $s;
            } else {
                $unlinkedStatements[] = $s;
            }
        }
        $airbnbDelta = $airbnbStatements === []
            ? null
            : round($summary->airbnbReservationSum - $airbnbStatementSum, 2);

        // Airbnb rezervace měsíce + jejich stav dokladu (pro „chybí doklad → nahrát").
        $airbnbReservations = array_values(array_filter(
            $summary->reservations,
            static fn (Reservation $r): bool => $r->getChannel() === Channel::AIRBNB,
        ));

        return [
            'year' => $year,
            'month' => $month,
            'reservations' => $summary->reservations,
            'invoice' => $invoice,
            'airbnbStatements' => $airbnbStatements,
            'airbnbStatementSum' => $airbnbStatementSum,
            'airbnbReservations' => $airbnbReservations,
            'airbnbStatementByReservation' => $statementByReservation,
            'airbnbUnlinkedStatements' => $unlinkedStatements,
            'sumBaseCzk' => $summary->sumBaseCzk,
            'sumVatCzk' => $summary->sumVatCzk,
            // Pro DPH přiznání (§101 ZDPH) — celé koruny.
            'filingBaseCzk' => (int) round($summary->sumBaseCzk),
            'filingVatCzk' => (int) round($summary->sumVatCzk),
            'bookingReservationSum' => $summary->bookingReservationSum,
            'bookingDelta' => $bookingDelta,
            'airbnbReservationSum' => $summary->airbnbReservationSum,
            'airbnbDelta' => $airbnbDelta,
            'period' => $period,
            'filingDueAt' => ($period ?? new VatPeriod($year, $month))->getFilingDueAt(),
            'liability' => $summary->liability($this->taxProfile->current()),
            'outputBaseCzk' => $summary->outputBaseCzk,
            'outputVatCzk' => $summary->outputVatCzk,
        ];
    }
}
