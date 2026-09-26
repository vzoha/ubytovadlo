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
use App\Entity\Reservation;
use App\Form\ReservationStayType;
use App\Formatting\Money;
use App\Repository\InvoiceRepository;
use App\Timeline\ReservationActionPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Úprava termínu a ceny u rezervace, kterou drží přímo Ubytovadlo. Ostatní
 * kanály přebírají termín ze zdroje, kde by ruční změnu přepsal další sync.
 */
class ReservationStayController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationActionPlanner $actionPlanner,
        private readonly IncomeUpserter $incomeUpserter,
        private readonly InvoiceRepository $invoices,
    ) {
    }

    #[Route('/reservation/{id}/termin', name: 'reservation_stay', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Reservation $reservation, Request $request): Response
    {
        if (!$reservation->getChannel()->ownsStay()) {
            $this->addFlash('warning', sprintf('Termín rezervace z kanálu %s se mění ve zdroji.', $reservation->getChannel()->label()));

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        $before = $this->stayFingerprint($reservation);
        $form = $this->createForm(ReservationStayType::class, $reservation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $reservation->setPriceTotal(Money::parse($reservation->getPriceTotal()));
            $this->actionPlanner->replan($reservation);
            $this->em->flush();
            $this->incomeUpserter->recompute($reservation);
            $this->addFlash('success', 'Termín a cena uloženy.');

            if ($before !== $this->stayFingerprint($reservation) && $this->invoices->findForReservation($reservation) !== []) {
                $this->addFlash('warning', 'Vystavené faktury se nezměnily — případnou opravu udělejte v sekci Fakturace.');
            }

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        return $this->render('reservation/stay_form.html.twig', [
            'reservation' => $reservation,
            'form' => $form->createView(),
            'invoices' => $this->invoices->findForReservation($reservation),
        ]);
    }

    private function stayFingerprint(Reservation $reservation): string
    {
        return implode('|', [
            $reservation->getCheckIn()->format('Y-m-d'),
            $reservation->getCheckOut()?->format('Y-m-d') ?? '',
            Money::parse($reservation->getPriceTotal()) ?? '',
        ]);
    }
}
