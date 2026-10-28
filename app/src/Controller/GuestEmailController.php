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
use App\Entity\GuestEmail;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Ruční přiřazení e-mailu k rezervaci hosta, když ho automatika nepřiřadila jistě. */
class GuestEmailController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/e-maily-hostu/{id}/rezervace', name: 'guest_email_assign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assign(GuestEmail $email, Request $request): Response
    {
        $this->assertCsrf($request, 'guest-email-assign-' . $email->getId());

        $customer = $email->getCustomer();
        $reservation = $this->reservations->find($request->request->getInt('reservation'));
        if ($customer === null || $reservation === null || $reservation->getCustomer() !== $customer) {
            throw new BadRequestHttpException('Rezervace nepatří hostovi, kterému patří e-mail.');
        }

        $email->assignReservation($reservation);
        $this->em->flush();
        $this->addFlash('success', 'E-mail přiřazen k rezervaci.');

        return $this->redirectToRoute('customer_detail', ['id' => $customer->getId()]);
    }
}
