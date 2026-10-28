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
use App\GuestMail\GuestEmailReplier;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

/** E-mail z korespondence s hostem: ruční přiřazení k rezervaci a odpověď z aplikace. */
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

    #[Route('/e-maily-hostu/{id}/odpoved', name: 'guest_email_reply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reply(GuestEmail $email, Request $request, GuestEmailReplier $replier): Response
    {
        $this->assertCsrf($request, 'guest-email-reply-' . $email->getId());
        $back = $this->backUrl($request);

        try {
            $replier->reply($email, $request->request->getString('subject'), $request->request->getString('text'));
            $this->addFlash('success', 'Odpověď odeslána.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('danger', 'Odpověď se nepodařilo odeslat: ' . $e->getMessage());
        }

        return $this->redirect($back);
    }

    /** Návrat na stránku, odkud se odpovídalo — jen cesta v rámci aplikace. */
    private function backUrl(Request $request): string
    {
        $back = $request->request->getString('back');

        $internal = str_starts_with($back, '/') && !str_starts_with($back, '//') && !str_starts_with($back, '/\\');

        return $internal ? $back : $this->generateUrl('customer_list');
    }
}
