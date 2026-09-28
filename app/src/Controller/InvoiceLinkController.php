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
use App\Entity\Invoice;
use App\Entity\InvoiceLink;
use App\Enum\ShareChannel;
use App\Invoice\InvoiceLinks;
use App\Mail\GuestPaymentText;
use App\Mail\GuestPhoneLinks;
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
 * Sdílení faktury odkazem (WhatsApp, SMS, chat). Majitel odkaz vytvoří
 * a dostane k němu hotový text zprávy; host přes odkaz otevře jen PDF té
 * jedné faktury, dokud odkaz platí.
 */
final class InvoiceLinkController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly InvoiceLinks $links,
        private readonly GuestPaymentText $texts,
        private readonly PdfStorage $pdfStorage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/invoice/{id}/odkaz', name: 'invoice_link_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function create(Invoice $invoice, Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'invoice-link-' . $invoice->getId());

        if ($this->pdfPath($invoice) === null) {
            return new JsonResponse(['error' => sprintf('Faktura %s nemá vygenerované PDF.', $invoice->getNumber())], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $issued = $this->links->issue($invoice);
        $text = $this->texts->invoice($invoice, $issued);

        return new JsonResponse([
            'id' => $issued->link->getId(),
            'url' => $issued->url,
            'text' => $text,
            'expires' => $issued->link->getExpiresAt()->format('j. n. Y'),
            ...GuestPhoneLinks::forReservation($invoice->getReservation()),
            'sentUrl' => $this->generateUrl('invoice_link_sent', ['id' => $issued->link->getId()]),
        ]);
    }

    /** Majitel zvolil kanál — zapíše se, kudy odkaz odešel. */
    #[Route('/invoice-link/{id}/odeslano', name: 'invoice_link_sent', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function sent(InvoiceLink $link, Request $request): Response
    {
        $this->assertCsrf($request, 'invoice-link-sent');

        $channel = ShareChannel::tryFrom((string) $request->request->get('channel'));
        if ($channel === null) {
            return new Response(null, Response::HTTP_BAD_REQUEST);
        }

        $link->markSentVia($channel);
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
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
