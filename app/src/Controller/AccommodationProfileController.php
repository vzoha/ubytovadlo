<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Form\PropertyType;
use App\Form\UbyportIdentifiersType;
use App\Formatting\PropertyAddress;
use App\Ubyport\AccommodationProfileWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ubytování je domov údajů o objektu (název, adresa, kontakt) — čtou je zprávy
 * hostům i hlášení na Ubyport. Ubyport má vlastní stránku jen na identifikátory
 * od cizinecké policie, adresu odtud jen ukazuje.
 */
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

        $form = $this->createForm(PropertyType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->writer->save($profile);
            $this->addFlash('success', 'Údaje o ubytování uloženy.');

            return $this->redirectToRoute('accommodation_profile_edit');
        }

        return $this->render('accommodation_profile/edit.html.twig', [
            'form' => $form->createView(),
            'isNew' => $profile->getId() === null,
        ]);
    }

    #[Route('/nastaveni/ubyport', name: 'ubyport_settings_edit', methods: ['GET', 'POST'])]
    public function ubyport(Request $request): Response
    {
        $profile = $this->writer->current();

        $form = $this->createForm(UbyportIdentifiersType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->writer->save($profile);
            $this->addFlash('success', 'Identifikátory pro Ubyport uloženy.');

            return $this->redirectToRoute('ubyport_settings_edit');
        }

        return $this->render('accommodation_profile/ubyport.html.twig', [
            'form' => $form->createView(),
            'profile' => $profile,
            'property_address' => PropertyAddress::format($profile),
        ]);
    }
}
