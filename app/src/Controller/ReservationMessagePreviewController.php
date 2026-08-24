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
use App\Entity\ReservationAction;
use App\Mail\ActionMessageResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Náhled zprávy, kterou naplánovaná akce odešle hostovi — s daty té rezervace,
 * stejnou cestou jako reálné odeslání. Vrací JSON pro modal na časové ose:
 * příjemce, předmět a HTML těla (do iframe, ať styly e-mailu nesahají na appku).
 */
class ReservationMessagePreviewController extends AbstractController
{
    public function __construct(
        private readonly ActionMessageResolver $messages,
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
            'to' => (string) $action->getReservation()->getGuestContact()->getEmail(),
            'subject' => $rendered->subject,
            'html' => $rendered->html,
        ]);
    }
}
