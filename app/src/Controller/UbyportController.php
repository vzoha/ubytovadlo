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
use App\Entity\Reservation;
use App\Repository\AccommodationProfileRepository;
use App\Ubyport\UbyportQueue;
use App\Ubyport\UbyportReceiptParser;
use App\Ubyport\UbyportRow;
use App\Ubyport\UnlExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dashboard hlášení ubytovaných cizinců do Ubyportu (ubyport.policie.cz).
 *
 * Model po rezervacích — UNL se vždy podává za jednu rezervaci. Tři fáze:
 *   1. K nahlášení  → "Stáhnout UNL" (jen cizinci té rezervace) ⇒ exportedAt
 *   2. Čeká na doručenku → "Nahrát doručenku (PDF)" ⇒ confirmedAt + počty
 *      (fallback: ruční potvrzení bez PDF)
 *   3. Nahlášeno    → datum, počty z doručenky, odkaz na PDF, vrátit do fronty
 */
class UbyportController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly AccommodationProfileRepository $profiles,
        private readonly UbyportQueue $queue,
        private readonly UnlExporter $exporter,
        private readonly UbyportReceiptParser $receiptParser,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/ubyport', name: 'ubyport_index', methods: ['GET'])]
    public function index(): Response
    {
        $today = new \DateTimeImmutable('today');
        $rows = $this->queue->rows($today);

        $byState = [
            UbyportRow::STATE_TO_REPORT => [],
            UbyportRow::STATE_INCOMPLETE => [],
            UbyportRow::STATE_AWAITING_RECEIPT => [],
            UbyportRow::STATE_REPORTED => [],
        ];
        foreach ($rows as $row) {
            $byState[$row->state][] = $row;
        }

        return $this->render('ubyport/index.html.twig', [
            'profile' => $this->profiles->getSingleton(),
            'toReport' => $byState[UbyportRow::STATE_TO_REPORT],
            'incomplete' => $byState[UbyportRow::STATE_INCOMPLETE],
            'awaiting' => $byState[UbyportRow::STATE_AWAITING_RECEIPT],
            'reported' => $byState[UbyportRow::STATE_REPORTED],
            'overdue' => \count(array_filter($rows, static fn (UbyportRow $r): bool => $r->isOverdue())),
        ]);
    }

    #[Route('/ubyport/{id}/export', name: 'ubyport_export', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function export(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'ubyport-export-' . $reservation->getId());

        $profile = $this->profiles->getSingleton();
        if ($profile === null) {
            $this->addFlash('danger', 'Nejdřív vyplň profil ubytování (IDUB + adresa) v Nastavení.');

            return $this->redirectToRoute('ubyport_index');
        }

        $foreigners = $this->queue->foreignersOf($reservation);
        if ($foreigners === [] || $reservation->getCheckOut() === null) {
            $this->addFlash('warning', 'Rezervace nemá kompletní cizince k nahlášení.');

            return $this->redirectToRoute('ubyport_index');
        }
        foreach ($foreigners as $g) {
            if ($g->getNationalityCode() === null || $g->getDocumentNumber() === null) {
                $this->addFlash('warning', 'U některého cizince chybí občanství nebo číslo dokladu — doplň je v check-inu.');

                return $this->redirectToRoute('ubyport_index');
            }
        }

        $now = new \DateTimeImmutable();
        $result = $this->exporter->build($profile, $foreigners, $now);
        $reservation->markUbyportExported($now);
        foreach ($foreigners as $g) {
            $g->markUbyportReported($now);
        }
        $this->em->flush();

        $response = new Response($result->content);
        $response->headers->set('Content-Type', 'text/plain; charset=windows-1250');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $result->filename),
        );

        return $response;
    }

    #[Route('/ubyport/{id}/dorucenka', name: 'ubyport_receipt_upload', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function uploadReceipt(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'ubyport-receipt-' . $reservation->getId());

        $file = $request->files->get('receipt');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('danger', 'Vyber PDF doručenky.');

            return $this->redirectToRoute('ubyport_index');
        }
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'pdf') {
            $this->addFlash('danger', 'Doručenka musí být PDF.');

            return $this->redirectToRoute('ubyport_index');
        }

        try {
            $data = $this->receiptParser->parseFile($file->getPathname());
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Nepodařilo se přečíst doručenku: ' . $e->getMessage());

            return $this->redirectToRoute('ubyport_index');
        }

        $profile = $this->profiles->getSingleton();
        if ($profile !== null && $data->idub !== null && $data->idub !== $profile->getIdub()) {
            $this->addFlash('warning', sprintf(
                'Pozor: IDUB v doručence (%s) nesedí na profil ubytování (%s).',
                $data->idub,
                $profile->getIdub(),
            ));
        }

        $storedName = $this->storeReceipt($reservation, $file);

        $now = new \DateTimeImmutable();
        $reservation->confirmUbyportReported($now, $storedName, $data->accepted, $data->rejected);
        $this->em->flush();

        $expected = \count($this->queue->foreignersOf($reservation));
        if ($data->isAllAccepted() && $data->accepted === $expected) {
            $this->addFlash('success', sprintf('Nahlášeno — %d přijatých, vše sedí.', $data->accepted));
        } else {
            $this->addFlash('warning', sprintf(
                'Doručenka uložena, ale zkontroluj: přijato %d, nepřijato %d, ignorováno %d (na rezervaci %d cizinců).',
                $data->accepted,
                $data->rejected,
                $data->ignored,
                $expected,
            ));
        }

        return $this->redirectToRoute('ubyport_index');
    }

    #[Route('/ubyport/{id}/oznacit', name: 'ubyport_confirm_manual', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirmManual(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'ubyport-confirm-' . $reservation->getId());

        $reservation->confirmUbyportReported(new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', 'Označeno jako nahlášené (bez doručenky).');

        return $this->redirectToRoute('ubyport_index');
    }

    #[Route('/ubyport/{id}/dorucenka/soubor', name: 'ubyport_receipt_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function downloadReceipt(Reservation $reservation): BinaryFileResponse
    {
        $filename = $reservation->getUbyportReceiptFilename();
        if ($filename === null) {
            throw new NotFoundHttpException();
        }

        $path = $this->receiptDir() . '/' . $filename;
        if (!is_file($path)) {
            throw new NotFoundHttpException();
        }

        return new BinaryFileResponse($path);
    }

    #[Route('/ubyport/{id}/vratit', name: 'ubyport_unreport', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unreport(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'ubyport-unreport-' . $reservation->getId());

        $reservation->resetUbyport();
        foreach ($this->queue->foreignersOf($reservation) as $g) {
            $g->markUbyportReported(null);
        }
        $this->em->flush();
        $this->addFlash('success', 'Vráceno do fronty k nahlášení.');

        return $this->redirectToRoute('ubyport_index');
    }

    private function storeReceipt(Reservation $reservation, UploadedFile $file): string
    {
        $dir = $this->receiptDir();
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Nelze vytvořit adresář: %s', $dir));
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName()) ?? 'dorucenka.pdf';
        $name = sprintf('%d-%s', $reservation->getId(), $safe);
        $file->move($dir, $name);

        return $name;
    }

    private function receiptDir(): string
    {
        return $this->projectDir . '/var/ubyport/receipts';
    }
}
