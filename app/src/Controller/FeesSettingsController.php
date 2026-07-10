<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Form\FeesSettingsType;
use App\Profit\ReservationProfitCalculator;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FeesSettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/nastaveni/poplatky', name: 'fees_settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $form = $this->createForm(FeesSettingsType::class, [
            'recreationFeePerAdultNight' => $this->settings->getInt(
                ReservationProfitCalculator::RECREATION_FEE_KEY,
                ReservationProfitCalculator::RECREATION_FEE_DEFAULT,
            ),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settings->set(
                ReservationProfitCalculator::RECREATION_FEE_KEY,
                (string) (int) $form->get('recreationFeePerAdultNight')->getData(),
                'Sazba rekreačního poplatku (Kč / dospělý / noc).',
            );
            $this->em->flush();
            $this->addFlash('success', 'Nastavení poplatků uloženo.');

            return $this->redirectToRoute('fees_settings_edit');
        }

        return $this->render('fees_settings/edit.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
