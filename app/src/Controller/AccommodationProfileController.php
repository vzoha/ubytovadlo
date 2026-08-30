<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Form\AccommodationProfileType;
use App\Ubyport\AccommodationProfileWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AccommodationProfileController extends AbstractController
{
    public function __construct(
        private readonly AccommodationProfileWriter $writer,
    ) {
    }

    #[Route('/nastaveni/ubytovani', name: 'accommodation_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $profile = $this->writer->current();

        $form = $this->createForm(AccommodationProfileType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->writer->save($profile);
            $this->addFlash('success', 'Údaje ubytovacího zařízení uloženy.');

            return $this->redirectToRoute('accommodation_profile_edit');
        }

        return $this->render('accommodation_profile/edit.html.twig', [
            'form' => $form->createView(),
            'isNew' => $profile->getId() === null,
        ]);
    }
}
