<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Credential\CredentialFormWriter;
use App\Credential\CredentialProvider;
use App\Form\MailboxSettingsType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Přístupy k poště — příchozí schránka a odchozí server. Nastavení jednotlivých
 * prodejních kanálů má vlastní stránku ({@see SalesChannelController}).
 */
class ConnectionSettingsController extends AbstractController
{
    public function __construct(
        private readonly CredentialProvider $provider,
        private readonly CredentialFormWriter $credentialWriter,
    ) {
    }

    #[Route('/nastaveni/pripojeni', name: 'connection_settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $state = $this->provider->formState();
        $form = $this->createForm(MailboxSettingsType::class, $state['values']);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->addFlash(
                $this->credentialWriter->save($form) ? 'success' : 'warning',
                $this->credentialWriter->isReady()
                    ? 'Přístupy k poště uloženy.'
                    : 'Přístupy vyžadují APP_CREDENTIALS_KEY (base64 32 B) v .env.local — bez něj se neuloží.',
            );

            return $this->afterSave($request);
        }

        return $this->render('connection_settings/edit.html.twig', [
            'form' => $form->createView(),
            'secretsSet' => $state['secretsSet'],
            'cipherReady' => $this->credentialWriter->isReady(),
        ]);
    }

    /**
     * Průvodce nastavením sem posílá s `?pruvodce=1` — po uložení se do něj
     * uživatel vrátí, aby mohl pokračovat dalším krokem.
     */
    private function afterSave(Request $request): Response
    {
        if ($request->query->getBoolean('pruvodce')) {
            return $this->redirectToRoute('setup_wizard_step', ['step' => 'pripojeni']);
        }

        return $this->redirectToRoute('connection_settings_edit');
    }
}
