<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Config\LogoStorage;
use App\Entity\Invoice;
use App\Entity\ReservationAction;
use App\Enum\MessageKind;
use App\Mail\ActionMessageResolver;
use App\Mail\GuestMessageRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Náhled zprávy hostovi před odesláním — naplánované akce i e-mailu s fakturou.
 * Renderuje se stejnou cestou a se stejnými daty jako reálné odeslání. Vrací JSON
 * pro modal: příjemce, předmět a HTML těla (do iframe, ať styly e-mailu nesahají
 * na appku) a textovou podobu pro vložení do chatu portálu.
 */
class ReservationMessagePreviewController extends AbstractController
{
    public function __construct(
        private readonly ActionMessageResolver $messages,
        private readonly GuestMessageRenderer $renderer,
        private readonly LogoStorage $logo,
    ) {
    }

    #[Route('/reservation/action/{id}/nahled', name: 'reservation_action_preview', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function preview(ReservationAction $action, Request $request): JsonResponse
    {
        $logoSrc = $this->logo->absoluteUrl($request->getSchemeAndHttpHost());
        $rendered = $this->messages->render($action, $logoSrc);

        if ($rendered === null) {
            throw $this->createNotFoundException('Tahle akce hostovi zprávu neposílá.');
        }

        return new JsonResponse([
            'to' => (string) $action->getReservation()->getGuestContact()->getDeliveryEmail(),
            'subject' => $rendered->subject,
            'html' => $rendered->html,
            'text' => $rendered->text,
            'label' => $action->getType()->label(),
        ]);
    }

    /**
     * Náhled e-mailu s fakturou — stejná šablona i data jako reálné odeslání,
     * navíc jméno přílohy, ať je vidět, že doklad opravdu jede s sebou.
     */
    #[Route('/invoice/{id}/nahled-mailu', name: 'invoice_message_preview', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function invoicePreview(Invoice $invoice, Request $request): JsonResponse
    {
        $reservation = $invoice->getReservation();
        $rendered = $this->renderer->render(
            MessageKind::INVOICE,
            $reservation,
            ['invoice_number' => $invoice->getNumber()],
            $this->logo->absoluteUrl($request->getSchemeAndHttpHost()),
        );

        return new JsonResponse([
            'to' => (string) $reservation->getGuestContact()->getDeliveryEmail(),
            'subject' => $rendered->subject,
            'html' => $rendered->html,
            'text' => $rendered->text,
            'label' => MessageKind::INVOICE->label(),
            'attachment' => $invoice->getNumber() . '.pdf',
        ]);
    }
}
