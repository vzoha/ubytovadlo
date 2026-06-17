<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Credential\CredentialCipher;
use App\Credential\CredentialProvider;
use App\Form\ConnectionSettingsType;
use App\Repository\CredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ConnectionSettingsController extends AbstractController
{
    public function __construct(
        private readonly CredentialProvider $provider,
        private readonly CredentialRepository $credentials,
        private readonly CredentialCipher $cipher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/nastaveni/pripojeni', name: 'connection_settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $state = $this->provider->formState();
        $form = $this->createForm(ConnectionSettingsType::class, $state['values']);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->cipher->isReady()) {
                $this->addFlash('danger', 'Chybí APP_CREDENTIALS_KEY (base64 32 B) — bez něj nelze údaje uložit. Doplň ho do .env.local.');

                return $this->redirectToRoute('connection_settings_edit');
            }

            foreach (CredentialProvider::FIELDS as $field => [$key, $isSecret]) {
                $value = trim((string) $form->get($field)->getData());
                // Tajemství s prázdným polem necháváme být (beze změny).
                if ($isSecret && $value === '') {
                    continue;
                }
                $this->credentials->setEncrypted($key, $value);
            }
            $this->em->flush();
            $this->addFlash('success', 'Přístupové údaje uloženy (šifrovaně).');

            return $this->redirectToRoute('connection_settings_edit');
        }

        return $this->render('connection_settings/edit.html.twig', [
            'form' => $form->createView(),
            'secretsSet' => $state['secretsSet'],
            'cipherReady' => $this->cipher->isReady(),
        ]);
    }
}
