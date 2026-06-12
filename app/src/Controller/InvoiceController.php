<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Enum\InvoiceType;
use App\Invoice\InvoiceService;
use App\Repository\InvoiceRepository;
use App\Storage\PdfStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

class InvoiceController extends AbstractController
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly EntityManagerInterface $em,
        private readonly PdfStorage $pdfStorage,
    ) {
    }

    #[Route('/reservation/{id}/invoice/deposit', name: 'invoice_issue_deposit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function issueDeposit(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'invoice-deposit-' . $reservation->getId());

        return $this->issue(
            $reservation,
            fn (): Invoice => $this->invoices->issueDeposit($reservation),
            fn (Invoice $i): string => sprintf('Vystavena zálohová faktura %s.', $i->getNumber()),
            'Nepodařilo se vystavit zálohu',
        );
    }

    #[Route('/reservation/{id}/invoice/final', name: 'invoice_issue_final', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function issueFinal(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'invoice-final-' . $reservation->getId());

        $deposit = $this->invoiceRepo->findFirstByReservationAndType($reservation, InvoiceType::DEPOSIT);
        if ($deposit === null) {
            $this->addFlash('warning', 'Nejprve vystav zálohovou fakturu.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        return $this->issue(
            $reservation,
            fn (): Invoice => $this->invoices->issueFinal($reservation, $deposit),
            fn (Invoice $i): string => sprintf('Vystavena konečná faktura %s.', $i->getNumber()),
            'Nepodařilo se vystavit doplatek',
        );
    }

    #[Route('/reservation/{id}/invoice/full', name: 'invoice_issue_full', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function issueFull(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'invoice-full-' . $reservation->getId());

        return $this->issue(
            $reservation,
            fn (): Invoice => $this->invoices->issueFull($reservation),
            fn (Invoice $i): string => sprintf('Vystavena faktura %s.', $i->getNumber()),
            'Nepodařilo se vystavit fakturu',
        );
    }

    /**
     * @param callable():Invoice       $factory
     * @param callable(Invoice):string $successMessage
     */
    private function issue(Reservation $reservation, callable $factory, callable $successMessage, string $errorPrefix): Response
    {
        try {
            $invoice = $factory();
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $errorPrefix . ': ' . $e->getMessage());

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        $this->addFlash('success', $successMessage($invoice));

        return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
    }

    #[Route('/invoice/{id}/mark-paid', name: 'invoice_mark_paid', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markPaid(Invoice $invoice, Request $request): Response
    {
        $this->assertCsrf($request, 'invoice-paid-' . $invoice->getId());
        $this->invoices->markPaid($invoice);
        $this->addFlash('success', sprintf('Faktura %s označena jako zaplacená.', $invoice->getNumber()));

        return $this->redirectToRoute('reservation_detail', ['id' => $invoice->getReservation()->getId()]);
    }

    #[Route('/invoice/{id}/edit', name: 'invoice_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Invoice $invoice, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'invoice-edit-' . $invoice->getId());

            $issuedAt = $this->parseDate((string) $request->request->get('issued_at'));
            $dueAt = $this->parseDate((string) $request->request->get('due_at'));
            $paidAtRaw = trim((string) $request->request->get('paid_at'));
            $paidAt = $paidAtRaw === '' ? null : $this->parseDate($paidAtRaw);
            $paymentMethod = trim((string) $request->request->get('payment_method', $invoice->getPaymentMethod()));

            $errors = [];
            $allowedMethods = array_unique([InvoiceService::PAYMENT_BANK, InvoiceService::PAYMENT_CASH, $invoice->getPaymentMethod()]);
            if (!in_array($paymentMethod, $allowedMethods, true)) {
                $errors[] = 'Neplatný způsob platby.';
            }
            if ($issuedAt === null) {
                $errors[] = 'Datum vystavení je povinné.';
            } elseif ((int) $issuedAt->format('Y') !== $invoice->getSeriesYear()) {
                $errors[] = sprintf('Datum vystavení musí být v roce %d (číselná řada faktury).', $invoice->getSeriesYear());
            }
            if ($dueAt === null) {
                $errors[] = 'Datum splatnosti je povinné.';
            }
            if ($issuedAt !== null && $dueAt !== null && $dueAt < $issuedAt) {
                $errors[] = 'Splatnost nemůže být před vystavením.';
            }
            if ($paidAt !== null && $issuedAt !== null && $paidAt < $issuedAt) {
                $errors[] = 'Datum platby nemůže být před vystavením.';
            }

            if ($errors === []) {
                $invoice->setIssuedAt($issuedAt);
                $invoice->setDueAt($dueAt);
                $invoice->setPaidAt($paidAt);

                if ($paymentMethod !== $invoice->getPaymentMethod()) {
                    $this->invoices->changePaymentMethod($invoice, $paymentMethod);
                } elseif ($invoice->getQrPayload() !== null) {
                    $this->invoices->refreshBankQr($invoice);
                }

                $this->em->flush();
                $this->invoices->regeneratePdf($invoice);

                $this->addFlash('success', sprintf('Faktura %s upravena.', $invoice->getNumber()));

                return $this->redirectToRoute('reservation_detail', ['id' => $invoice->getReservation()->getId()]);
            }

            foreach ($errors as $error) {
                $this->addFlash('danger', $error);
            }
        }

        return $this->render('invoice/edit.html.twig', [
            'invoice' => $invoice,
            'reservation' => $invoice->getReservation(),
        ]);
    }

    private function parseDate(string $input): ?\DateTimeImmutable
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $input);

        return $date === false ? null : $date;
    }

    #[Route('/invoice/{id}/pdf', name: 'invoice_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function pdf(Invoice $invoice): Response
    {
        $stored = $invoice->getPdfPath();
        $path = $stored === null ? null : $this->pdfStorage->absolute($stored);
        if ($path === null || !is_file($path)) {
            throw $this->createNotFoundException('PDF nebylo vygenerováno.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $invoice->getNumber() . '.pdf');
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
