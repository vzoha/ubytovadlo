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
use App\Entity\ReservationAction;
use App\Enum\ActionDelivery;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\ShareChannel;
use App\Invoice\InvoiceLinks;
use App\Mail\GuestPaymentText;
use App\Mail\GuestPhoneLinks;
use App\Repository\InvoiceRepository;
use App\Storage\PdfStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Připomínka doplatku mimo poštu aplikace — WhatsApp, SMS, chat nebo PDF
 * z telefonu. Text nese platební údaje a u vystavené faktury odkaz na její
 * PDF; po odeslání se akce na časové ose uzavře s kanálem ve výsledku.
 */
final class BalanceReminderController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceLinks $links,
        private readonly GuestPaymentText $texts,
        private readonly PdfStorage $pdfStorage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/reservation/action/{id}/pripominka', name: 'balance_reminder_text', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function text(ReservationAction $action, Request $request): JsonResponse
    {
        $this->assertOpenReminder($action);
        $this->assertCsrf($request, 'action-edit-' . $action->getId());

        $reservation = $action->getReservation();
        $invoice = $this->invoices->findUnpaidBalanceInvoice($reservation);
        $issued = $invoice !== null && $this->pdfStorage->existing($invoice->getPdfPath()) !== null
            ? $this->links->issue($invoice)
            : null;

        return new JsonResponse([
            'id' => $issued?->link->getId(),
            'url' => $issued?->url,
            'text' => $this->texts->reminder($reservation, $invoice, $issued),
            ...GuestPhoneLinks::forReservation($reservation),
            'sentUrl' => $issued !== null ? $this->generateUrl('invoice_link_sent', ['id' => $issued->link->getId()]) : null,
        ]);
    }

    /** Majitel zvolil kanál — připomínka je vyřízená. */
    #[Route('/reservation/action/{id}/odeslano', name: 'balance_reminder_sent', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function sent(ReservationAction $action, Request $request): Response
    {
        $this->assertOpenReminder($action);
        $this->assertCsrf($request, 'action-edit-' . $action->getId());

        $channel = ShareChannel::tryFrom((string) $request->request->get('channel'));
        if ($channel === null) {
            return new Response(null, Response::HTTP_BAD_REQUEST);
        }

        $action->markDone($channel->sentResult(), ActionDelivery::MANUAL);
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Ručně uzavřená zpráva, která hostovi nakonec neodešla, se vrátí mezi otevřené. */
    #[Route('/reservation/action/{id}/znovu-otevrit', name: 'reservation_action_reopen', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reopen(ReservationAction $action, Request $request): Response
    {
        $this->assertCsrf($request, 'action-edit-' . $action->getId());

        if ($action->getStatus() !== ActionStatus::DONE || $action->getDelivery() !== ActionDelivery::MANUAL) {
            throw new NotFoundHttpException();
        }

        $action->replan();
        $this->em->flush();
        $this->addFlash('success', 'Akce je znovu otevřená.');

        return $this->redirectToRoute('reservation_detail', ['id' => $action->getReservation()->getId()]);
    }

    private function assertOpenReminder(ReservationAction $action): void
    {
        if ($action->getType() !== ActionType::BALANCE_REMINDER || !$action->getStatus()->isOpen()) {
            throw new NotFoundHttpException();
        }
    }
}
