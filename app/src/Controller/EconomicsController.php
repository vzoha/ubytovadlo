<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Channel;
use App\Profit\RecreationFeeReportBuilder;
use App\Profit\YearEconomicsBuilder;
use App\Repository\GuestDocumentRepository;
use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Roční ekonomický přehled — náhrada za ruční tabulkovou evidenci:
 * tabulka rezervací s rozpadem výdajů a ziskem + součty dle kanálu,
 * rozdělené na uskutečněné a očekávané pobyty. Samostatnou stránkou
 * dodává i podklad rekreačního poplatku pro obec (přehled + CSV).
 */
class EconomicsController extends AbstractController
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly YearEconomicsBuilder $economics,
        private readonly RecreationFeeReportBuilder $recreationFees,
        private readonly GuestDocumentRepository $guestDocuments,
    ) {
    }

    #[Route('/ekonomika/{year}', name: 'economics_overview', methods: ['GET'], requirements: ['year' => '\d{4}'])]
    public function overview(?int $year = null): Response
    {
        $today = new \DateTimeImmutable('today');
        $year ??= (int) $today->format('Y');

        $economics = $this->economics->build($year, $today);

        return $this->render('economics/overview.html.twig', [
            'year' => $year,
            'years' => $this->reservations->findDistinctCheckInYears(),
            'today' => $today,
            'channels' => Channel::cases(),
        ] + $economics);
    }

    #[Route('/ekonomika/poplatky/{year}', name: 'recreation_fee_report', methods: ['GET'], requirements: ['year' => '\d{4}'])]
    public function recreationFees(?int $year = null): Response
    {
        $today = new \DateTimeImmutable('today');
        $year ??= (int) $today->format('Y');

        $report = $this->recreationFees->build($year, $today);

        return $this->render('economics/fee_report.html.twig', [
            'year' => $year,
            'years' => $this->reservations->findDistinctCheckInYears(),
            'today' => $today,
        ] + $report);
    }

    #[Route('/ekonomika/poplatky/{year}/podklad.csv', name: 'recreation_fee_report_csv', methods: ['GET'], requirements: ['year' => '\d{4}'])]
    public function recreationFeesCsv(int $year): Response
    {
        $report = $this->recreationFees->build($year, new \DateTimeImmutable('today'));

        $out = fopen('php://temp', 'r+');
        \assert($out !== false);
        // UTF-8 BOM — Excel jinak háže diakritiku.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ['Příjezd', 'Odjezd', 'Jméno', 'Kanál', 'Dospělí', 'Děti', 'Nocí', 'Poplatek Kč'], ';', '"', '');
        foreach ($report['reservations'] as $reservation) {
            $profit = $report['profits'][$reservation->getId()];
            fputcsv($out, [
                $reservation->getCheckIn()->format('d.m.Y'),
                $reservation->getCheckOut()?->format('d.m.Y') ?? '',
                $reservation->getGuestName() ?? '',
                $reservation->getChannel()->label(),
                $reservation->getGuestsAdult(),
                $reservation->getGuestsChild(),
                $profit->nights,
                $profit->recreationFeeCzk,
            ], ';', '"', '');
        }

        $total = $report['total'];
        fputcsv($out, ['Celkem', '', '', '', $total['adults'], '', $total['nights'], $total['fee']], ';', '"', '');

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="rekreacni-poplatek-%04d.csv"', $year));

        return $response;
    }

    #[Route('/ekonomika/kniha-hostu/{year}', name: 'guest_book', methods: ['GET'], requirements: ['year' => '\d{4}'])]
    public function guestBook(?int $year = null): Response
    {
        $year ??= (int) (new \DateTimeImmutable('today'))->format('Y');
        [$from, $to] = $this->yearRange($year);

        return $this->render('economics/guest_book.html.twig', [
            'year' => $year,
            'years' => $this->reservations->findDistinctCheckInYears(),
            'documents' => $this->guestDocuments->findForGuestBook($from, $to),
        ]);
    }

    #[Route('/ekonomika/kniha-hostu/{year}/kniha.csv', name: 'guest_book_csv', methods: ['GET'], requirements: ['year' => '\d{4}'])]
    public function guestBookCsv(int $year): Response
    {
        [$from, $to] = $this->yearRange($year);
        $documents = $this->guestDocuments->findForGuestBook($from, $to);

        $out = fopen('php://temp', 'r+');
        \assert($out !== false);
        // UTF-8 BOM — Excel jinak háže diakritiku.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ['Příjmení', 'Jméno', 'Datum narození', 'Občanství', 'Druh dokladu', 'Číslo dokladu', 'Adresa bydliště', 'Příjezd', 'Odjezd', 'Nocí'], ';', '"', '');
        foreach ($documents as $doc) {
            $reservation = $doc->getReservation();
            $checkOut = $reservation->getCheckOut();
            fputcsv($out, [
                $doc->getLastName(),
                $doc->getFirstName(),
                $doc->getBirthDate()->format('d.m.Y'),
                $doc->isCzechCitizen() ? 'ČR' : ($doc->getNationalityCode() ?? ''),
                $doc->getDocumentType()?->label() ?? '',
                $doc->getDocumentNumber() ?? '',
                (string) $doc->getResidenceAddress(),
                $reservation->getCheckIn()->format('d.m.Y'),
                $checkOut?->format('d.m.Y') ?? '',
                $checkOut !== null ? $checkOut->diff($reservation->getCheckIn())->days : '',
            ], ';', '"', '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="kniha-hostu-%04d.csv"', $year));

        return $response;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function yearRange(int $year): array
    {
        return [
            new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year)),
            new \DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $year)),
        ];
    }
}
