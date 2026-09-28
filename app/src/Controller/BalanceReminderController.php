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
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Enum\ActionDelivery;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\ShareChannel;
use App\Invoice\InvoiceLinks;
use App\Mail\GuestPaymentText;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationActionRepository;
use App\Storage\PdfStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Připomínka doplatku přes WhatsApp, SMS nebo chat. Text nese platební údaje
 * a u vystavené faktury odkaz na její PDF; odeslaná přes telefon uzavře
 * otevřenou připomínku na časové ose.
 */
final class BalanceReminderController extends AbstractController
{
    use ChecksCsrf;
    use RespondsWithGuestText;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly ReservationActionRepository $actions,
        private readonly InvoiceLinks $links,
        private readonly GuestPaymentText $texts,
        private readonly PdfStorage $pdfStorage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/reservation/{id}/pripominka-doplatku', name: 'balance_reminder_message', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function message(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'balance-reminder-' . $reservation->getId());
        $channel = $this->channel($request);

        $invoice = $this->invoices->findUnpaidBalanceInvoice($reservation);
        $issued = $invoice !== null && $this->pdfStorage->existing($invoice->getPdfPath()) !== null
            ? $this->links->obtain($invoice)
            : null;
        // Z chatu appka odeslání nevidí — tam připomínku uzavře ubytovatel sám.
        if ($channel !== ShareChannel::COPY) {
            $issued?->link->markSentVia($channel);
            $this->closeOpenReminders($reservation, $channel);
            $this->em->flush();
        }

        return $this->guestTextResponse($reservation, $channel, $this->texts->reminder($reservation, $invoice, $issued));
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

    private function closeOpenReminders(Reservation $reservation, ShareChannel $channel): void
    {
        foreach ($this->actions->findOpenForReservation($reservation) as $action) {
            if ($action->getType() === ActionType::BALANCE_REMINDER) {
                $action->markDone($channel->sentResult(), ActionDelivery::MANUAL);
            }
        }
    }
}
