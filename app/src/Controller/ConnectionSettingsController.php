<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Connector\ConnectorManager;
use App\Credential\CredentialCipher;
use App\Credential\CredentialProvider;
use App\Enum\ConnectorType;
use App\Form\ConnectionSettingsType;
use App\Ical\IcalFeedToken;
use App\MotoPress\MotoPressSettings;
use App\Repository\CredentialRepository;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ConnectionSettingsController extends AbstractController
{
    public function __construct(
        private readonly CredentialProvider $provider,
        private readonly CredentialRepository $credentials,
        private readonly CredentialCipher $cipher,
        private readonly MotoPressSettings $motopress,
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
        private readonly IcalFeedToken $icalFeedToken,
        private readonly ConnectorManager $connectors,
    ) {
    }

    #[Route('/nastaveni/pripojeni', name: 'connection_settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $state = $this->provider->formState();
        $form = $this->createForm(ConnectionSettingsType::class, $state['values'] + $this->motopress->currentValues());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Chování MotoPressu (Setting) nešifrujeme — uloží se i bez klíče.
            $this->saveMapping(
                $form->get('petServiceIds')->getData(),
                $form->get('babyCotServiceIds')->getData(),
                (bool) $form->get('pushPayments')->getData(),
            );

            if (!$this->cipher->isReady()) {
                $this->addFlash('warning', 'Chování MotoPressu uloženo. Přístupové údaje ale vyžadují APP_CREDENTIALS_KEY (base64 32 B) v .env.local — bez něj se neuloží.');

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
            $this->addFlash('success', 'Nastavení připojení uloženo.');

            return $this->redirectToRoute('connection_settings_edit');
        }

        $icalFeedUrl = $this->generateUrl(
            'ical_feed',
            ['token' => $this->icalFeedToken->getOrCreate()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $motopressWebhookUrl = $this->generateUrl(
            'motopress_webhook',
            ['token' => $this->connectors->getOrCreateWebhookToken(ConnectorType::MOTOPRESS)],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->render('connection_settings/edit.html.twig', [
            'form' => $form->createView(),
            'secretsSet' => $state['secretsSet'],
            'cipherReady' => $this->cipher->isReady(),
            'icalFeedUrl' => $icalFeedUrl,
            'motopressWebhookUrl' => $motopressWebhookUrl,
            'motopressType' => ConnectorType::MOTOPRESS->value,
            'connectors' => $this->connectors->health(),
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
