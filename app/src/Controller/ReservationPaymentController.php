<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Cashflow\IncomeUpserter;
use App\Controller\Concern\ChecksCsrf;
use App\Controller\Concern\ParsesRequestInput;
use App\Entity\Reservation;
use App\Entity\ReservationReceipt;
use App\Enum\ReceiptOrigin;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ruční peníze u rezervace — platba hosta u přímých rezervací a reálná výplata
 * u OTA. Oboje zpřesňuje příjem rezervace v cashflow ({@see IncomeUpserter}).
 */
class ReservationPaymentController extends AbstractController
{
    use ChecksCsrf;
    use ParsesRequestInput;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IncomeUpserter $incomeUpserter,
        private readonly AccountRepository $accounts,
    ) {
    }

    #[Route('/reservation/{id}/payout', name: 'reservation_record_payout', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function recordPayout(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'payout-' . $reservation->getId());

        // Ruční výplata je OTA koncept (Airbnb/Booking) — u web rezervace host platí
        // přímo a příjem se drží z faktur, ne z výplaty.
        if (!$reservation->getChannel()->isOta()) {
            $this->addFlash('warning', 'Reálná výplata se zadává jen u OTA rezervací (Airbnb/Booking).');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        $amount = $this->parseAmountOrNull($request->request->getString('amount'));
        if ($amount === null || (float) $amount <= 0) {
            $this->addFlash('warning', 'Zadej částku výplaty.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        $receivedOn = $this->parseDateOrNull($request->request->getString('received_on')) ?? new \DateTimeImmutable('today');
        $this->incomeUpserter->recordManualPayout($reservation, $amount, $receivedOn);
        $this->addFlash('success', 'Výplata zaznamenána — příjem rezervace zpřesněn.');

        return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
    }

    #[Route('/reservation/{id}/payment', name: 'reservation_record_payment', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function recordPayment(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'payment-' . $reservation->getId());

        // Ruční platba hosta je web/přímý koncept — u OTA platí host platformě
        // a reálné peníze řeší „Reálná výplata".
        if ($reservation->getChannel()->isOta()) {
            $this->addFlash('warning', 'U OTA rezervací zadej reálnou výplatu, ne platbu hosta.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        $amount = $this->parseAmountOrNull($request->request->getString('amount'));
        if ($amount === null || (float) $amount <= 0) {
            $this->addFlash('warning', 'Zadej částku platby.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        $receivedOn = $this->parseDateOrNull($request->request->getString('received_on')) ?? new \DateTimeImmutable('today');
        $account = $this->accounts->findChosen($request->request->getInt('account'));
        $this->incomeUpserter->recordManualPayment($reservation, $amount, $receivedOn, $account);
        $this->addFlash('success', 'Platba zaznamenána.');

        return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
    }

    #[Route('/reservation/payment/{id}/delete', name: 'reservation_delete_payment', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deletePayment(ReservationReceipt $receipt, Request $request): Response
    {
        $this->assertCsrf($request, 'payment-delete-' . $receipt->getId());

        if ($receipt->getOriginType() !== ReceiptOrigin::MANUAL_PAYMENT) {
            throw $this->createNotFoundException();
        }

        $reservationId = $receipt->getReservation()->getId();
        $this->em->remove($receipt);
        $this->em->flush();
        $this->addFlash('success', 'Platba smazána.');

        return $this->redirectToRoute('reservation_detail', ['id' => $reservationId]);
    }
}
