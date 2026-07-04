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
use App\Form\MotoPressMappingType;
use App\MotoPress\MotoPressSettings;
use App\Repository\CredentialRepository;
use App\Repository\SettingRepository;
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
        private readonly MotoPressSettings $motopress,
        private readonly SettingRepository $settings,
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

        $mappingForm = $this->createForm(MotoPressMappingType::class, $this->motopress->currentValues());
        $mappingForm->handleRequest($request);
        if ($mappingForm->isSubmitted() && $mappingForm->isValid()) {
            $this->saveMapping($mappingForm->get('petServiceIds')->getData(), $mappingForm->get('babyCotServiceIds')->getData(), (bool) $mappingForm->get('pushPayments')->getData());
            $this->addFlash('success', 'Nastavení MotoPressu uloženo.');

            return $this->redirectToRoute('connection_settings_edit');
        }

        return $this->render('connection_settings/edit.html.twig', [
            'form' => $form->createView(),
            'mappingForm' => $mappingForm->createView(),
            'secretsSet' => $state['secretsSet'],
            'cipherReady' => $this->cipher->isReady(),
        ]);
    }

    private function saveMapping(?string $petIds, ?string $babyCotIds, bool $push): void
    {
        // Vstup normalizujeme přes parseIds, ať se uloží čistý seznam ID.
        $this->settings->set(MotoPressSettings::KEY_PET, implode(',', MotoPressSettings::parseIds((string) $petIds)), 'MotoPress: ID služeb „pes".');
        $this->settings->set(MotoPressSettings::KEY_BABY_COT, implode(',', MotoPressSettings::parseIds((string) $babyCotIds)), 'MotoPress: ID služeb „dětská postýlka".');
        $this->settings->set(MotoPressSettings::KEY_PUSH, $push ? '1' : '0', 'MotoPress: posílat platby zpět.');
        $this->em->flush();
    }
}
