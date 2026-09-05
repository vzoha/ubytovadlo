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
use App\Enum\ReservationStatus;
use App\MotoPress\MotoPressApiException;
use App\MotoPress\MotoPressBookingStatusWriter;
use App\Reservation\ReservationCanceller;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Storno rezervace a jeho zrušení. U rezervací z vlastního webu umí storno
 * propsat i do MotoPressu, aby se termín vrátil do prodeje. Prodejní portály
 * (Booking, Airbnb) rozhodují o stornu samy — tam je tohle jen evidence.
 */
class ReservationStatusController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly ReservationCanceller $canceller,
        private readonly MotoPressBookingStatusWriter $motopress,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/reservation/{id}/cancel', name: 'reservation_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'cancel-' . $reservation->getId());

        if ($reservation->getStatus() === ReservationStatus::CANCELLED) {
            $this->addFlash('warning', 'Rezervace už je zrušená.');

            return $this->back($reservation);
        }

        $this->canceller->cancel($reservation);
        $this->addFlash('success', 'Rezervace zrušena. Naplánované akce jsou zavřené, hostovi už nic neodejde.');

        if ($request->request->getBoolean('push_motopress')) {
            $this->push($reservation, $this->motopress->cancel(...), 'Termín je v MotoPressu uvolněný.');
        }

        return $this->back($reservation);
    }

    #[Route('/reservation/{id}/restore', name: 'reservation_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restore(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'restore-' . $reservation->getId());

        if ($reservation->getStatus() !== ReservationStatus::CANCELLED) {
            $this->addFlash('warning', 'Rezervace není zrušená.');

            return $this->back($reservation);
        }

        $status = $this->canceller->restore($reservation);
        $this->addFlash('success', sprintf('Rezervace obnovena do stavu „%s".', $status->label()));

        if ($request->request->getBoolean('push_motopress')) {
            $this->push($reservation, $this->motopress->confirm(...), 'Termín je v MotoPressu opět obsazený.');
        }

        return $this->back($reservation);
    }

    /**
     * Zápis do MotoPressu je vedlejší krok: stav v Ubytovadle už je uložený, a
     * když se propsání nepovede, majitel se to dozví a zbytek dořeší ve WordPressu.
     *
     * @param callable(Reservation): void $write
     */
    private function push(Reservation $reservation, callable $write, string $done): void
    {
        if (!$this->motopress->supports($reservation)) {
            $this->addFlash('warning', 'Do MotoPressu se stav nepropsal — chybí ID rezervace nebo přístupové údaje.');

            return;
        }

        try {
            $write($reservation);
            $this->addFlash('success', $done);
        } catch (MotoPressApiException $e) {
            $this->logger->error('Propsání stavu rezervace do MotoPressu selhalo', [
                'reservation' => $reservation->getId(),
                'error' => $e->getMessage(),
            ]);
            $this->addFlash('warning', 'MotoPress zápis odmítl — stav změň ve WordPressu ručně. Detail je v logu.');
        }
    }

    private function back(Reservation $reservation): Response
    {
        return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
    }
}
