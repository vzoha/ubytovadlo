<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Config\InstanceSettings;
use App\Form\GeneralSettingsType;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GeneralSettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly InstanceSettings $instance,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/nastaveni/obecne', name: 'general_settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $form = $this->createForm(GeneralSettingsType::class, $this->instance->currentValues());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settings->set(
                InstanceSettings::KEY_BRAND_NAME,
                trim((string) $form->get('brandName')->getData()),
                'Název instance (brand).',
            );
            $this->settings->set(
                InstanceSettings::KEY_BASE_URL,
                trim((string) $form->get('baseUrl')->getData()),
                'Veřejná adresa aplikace pro odkazy v e-mailech.',
            );
            $this->em->flush();
            $this->addFlash('success', 'Obecné nastavení uloženo.');

            return $this->redirectToRoute('general_settings_edit');
        }

        return $this->render('general_settings/edit.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
