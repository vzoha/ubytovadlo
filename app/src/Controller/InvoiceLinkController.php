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
use App\Controller\Concern\RespondsWithGuestText;
use App\Entity\Invoice;
use App\Entity\InvoiceLink;
use App\Enum\ShareChannel;
use App\Invoice\InvoiceLinks;
use App\Mail\GuestPaymentText;
use App\Storage\PdfStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Faktura odkazem (WhatsApp, SMS, chat). Majitel pošle hotový text s odkazem
 * na PDF; host přes odkaz otevře jen PDF té jedné faktury, dokud odkaz platí.
 */
final class InvoiceLinkController extends AbstractController
{
    use ChecksCsrf;
    use RespondsWithGuestText;

    public function __construct(
        private readonly InvoiceLinks $links,
        private readonly GuestPaymentText $texts,
        private readonly PdfStorage $pdfStorage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Zpráva s fakturou pro WhatsApp, SMS nebo chat. Použije platný odkaz,
     * jinak vytvoří nový.
     */
    #[Route('/invoice/{id}/zprava', name: 'invoice_guest_message', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function message(Invoice $invoice, Request $request): Response
    {
        $this->assertCsrf($request, 'invoice-message-' . $invoice->getId());
        $channel = $this->channel($request);
        $reservation = $invoice->getReservation();

        if ($this->pdfPath($invoice) === null) {
            $error = sprintf('Faktura %s nemá vygenerované PDF.', $invoice->getNumber());
            if ($channel === ShareChannel::COPY) {
                return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('danger', $error);

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        $issued = $this->links->obtain($invoice);
        // Text pro chat se jen připraví — kudy odešel, zapíšeme u WhatsAppu a SMS.
        if ($channel !== ShareChannel::COPY) {
            $issued->link->markSentVia($channel);
            $this->em->flush();
        }

        return $this->guestTextResponse($reservation, $channel, $this->texts->invoice($invoice, $issued));
    }

    #[Route('/invoice-link/{id}/zrusit', name: 'invoice_link_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(InvoiceLink $link, Request $request): Response
    {
        $this->assertCsrf($request, 'invoice-link-revoke-' . $link->getId());

        $this->links->revoke($link);
        $invoice = $link->getInvoice();
        $this->addFlash('success', sprintf('Odkaz na fakturu %s je zrušený.', $invoice->getNumber()));

        return $this->redirectToRoute('reservation_detail', ['id' => $invoice->getReservation()->getId()]);
    }

    /**
     * Veřejné PDF podle tokenu. Neznámý, vypršelý i zrušený odkaz vrací stejnou
     * stránku (410), ať adresa neprozrazuje, jestli kdy existovala.
     */
    #[Route('/f/{token}', name: 'invoice_link_open', methods: ['GET'], requirements: ['token' => InvoiceLinks::TOKEN_PATTERN])]
    public function open(string $token): Response
    {
        $link = $this->links->open($token);
        $path = $link === null ? null : $this->pdfPath($link->getInvoice());
        if ($link === null || $path === null) {
            return $this->privateResponse($this->render('invoice_link/unavailable.html.twig', [], new Response(status: Response::HTTP_GONE)));
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $link->getInvoice()->getNumber() . '.pdf');
        $response->headers->set('Content-Type', 'application/pdf');

        return $this->privateResponse($response);
    }

    /** Odkaz nese osobní údaje — necachovat, neindexovat, neposílat dál v Refereru. */
    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function pdfPath(Invoice $invoice): ?string
    {
        return $this->pdfStorage->existing($invoice->getPdfPath());
    }
}
