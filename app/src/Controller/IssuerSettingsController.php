<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Form\IssuerSettingsType;
use App\Invoice\IssuerProfileProvider;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IssuerSettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly IssuerProfileProvider $issuerProvider,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/nastaveni/dodavatel', name: 'issuer_settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $form = $this->createForm(IssuerSettingsType::class, $this->issuerProvider->currentValues());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach (IssuerProfileProvider::KEYS as $field => $key) {
                $value = trim((string) $form->get($field)->getData());
                $this->settings->set($key, $value, 'Dodavatel na faktuře.');
            }
            $this->em->flush();
            $this->addFlash('success', 'Údaje dodavatele uloženy. Nové faktury je použijí; u stávajících přegeneruj PDF.');

            return $this->redirectToRoute('issuer_settings_edit');
        }

        return $this->render('issuer_settings/edit.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
