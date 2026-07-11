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
use App\Config\InstanceSettingsWriter;
use App\Config\LogoStorage;
use App\Form\GeneralSettingsType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GeneralSettingsController extends AbstractController
{
    public function __construct(
        private readonly InstanceSettings $instance,
        private readonly InstanceSettingsWriter $writer,
        private readonly LogoStorage $logo,
    ) {
    }

    #[Route('/nastaveni/obecne', name: 'general_settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $form = $this->createForm(GeneralSettingsType::class, $this->instance->currentValues());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->writer->save($form);
            $this->addFlash('success', 'Obecné nastavení uloženo.');

            return $this->redirectToRoute('general_settings_edit');
        }

        return $this->render('general_settings/edit.html.twig', [
            'form' => $form->createView(),
            'hasLogo' => $this->logo->exists(),
            'logoUrl' => $this->logo->publicPath(),
        ]);
    }

    #[Route('/nastaveni/obecne/logo/smazat', name: 'general_settings_logo_delete', methods: ['POST'])]
    public function deleteLogo(Request $request): Response
    {
        if ($this->isCsrfTokenValid('general_settings_logo_delete', (string) $request->request->get('_token'))) {
            $this->logo->remove();
            $this->addFlash('success', 'Logo odebráno.');
        } else {
            $this->addFlash('danger', 'Neplatný bezpečnostní token, zkus to znovu.');
        }

        return $this->redirectToRoute('general_settings_edit');
    }
}
