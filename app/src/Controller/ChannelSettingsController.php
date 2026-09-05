<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Booking\BookingHotelId;
use App\Connector\ConnectorHealth;
use App\Connector\ConnectorManager;
use App\Credential\CredentialFormWriter;
use App\Credential\CredentialProvider;
use App\Enum\ConnectorType;
use App\Form\BookingChannelType;
use App\Form\MotoPressChannelType;
use App\Ical\IcalFeedToken;
use App\MotoPress\MotoPressSettings;
use App\Repository\SettingRepository;
use App\Reservation\ExtranetLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Odkud chodí rezervace a platby — jeden konektor = jedna karta se stavem,
 * přepínačem, feedem obsazenosti i vlastním nastavením. Prodejní kanály
 * a zdroje plateb jsou dvě skupiny téhož seznamu. Konektory, které instance
 * nepoužívá, se nevypisují; přidávají se tlačítkem, které je rovnou zapne.
 */
class ChannelSettingsController extends AbstractController
{
    public function __construct(
        private readonly ConnectorManager $connectors,
        private readonly CredentialProvider $provider,
        private readonly CredentialFormWriter $credentialWriter,
        private readonly MotoPressSettings $motopress,
        private readonly ExtranetLink $extranetLink,
        private readonly SettingRepository $settings,
        private readonly IcalFeedToken $icalFeedToken,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/nastaveni/kanaly', name: 'channel_settings_index', methods: ['GET'])]
    public function index(): Response
    {
        $state = $this->provider->formState();
        $health = $this->connectors->health();

        return $this->render('channels/index.html.twig', [
            'active' => array_values(array_filter($health, self::isInUse(...))),
            'available' => array_values(array_filter($health, static fn (ConnectorHealth $c): bool => !self::isInUse($c))),
            'secretsSet' => $state['secretsSet'],
            'cipherReady' => $this->credentialWriter->isReady(),
            'motopressForm' => $this->motopressForm($state['values'])->createView(),
            'bookingForm' => $this->bookingForm()->createView(),
            'icalFeedUrl' => $this->absolute('ical_feed', ['token' => $this->icalFeedToken->getOrCreate()]),
            'motopressWebhookUrl' => $this->absolute('motopress_webhook', [
                'token' => $this->connectors->getOrCreateWebhookToken(ConnectorType::MOTOPRESS),
            ]),
        ]);
    }

    #[Route('/nastaveni/kanaly/{type}/pridat', name: 'channel_settings_add', methods: ['POST'])]
    public function add(string $type, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('connector', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $connector = ConnectorType::tryFrom($type) ?? throw $this->createNotFoundException();
        $this->connectors->setEnabled($connector, true);
        $this->addFlash('success', sprintf('Napojení „%s" přidáno — doplňte mu nastavení.', $connector->label()));

        return $this->redirectToRoute('channel_settings_index');
    }

    #[Route('/nastaveni/kanaly/booking', name: 'channel_settings_booking_save', methods: ['POST'])]
    public function saveBooking(Request $request): Response
    {
        $form = $this->bookingForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settings->set(
                BookingHotelId::SETTING_KEY,
                BookingHotelId::normalize((string) $form->get('bookingHotelId')->getData()) ?? '',
                'Booking.com: ID ubytování pro odkaz do extranetu.',
            );
            $this->em->flush();
            $this->addFlash('success', 'Nastavení Booking.com uloženo.');
        }

        return $this->redirectToRoute('channel_settings_index');
    }

    #[Route('/nastaveni/kanaly/motopress', name: 'channel_settings_motopress_save', methods: ['POST'])]
    public function saveMotoPress(Request $request): Response
    {
        $form = $this->motopressForm($this->provider->formState()['values']);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Chování MotoPressu (Setting) nešifrujeme — uloží se i bez klíče.
            $this->saveMapping(
                (string) $form->get('petServiceIds')->getData(),
                (string) $form->get('babyCotServiceIds')->getData(),
                (bool) $form->get('pushPayments')->getData(),
            );

            $this->addFlash(
                $this->credentialWriter->save($form) ? 'success' : 'warning',
                $this->credentialWriter->isReady()
                    ? 'Nastavení vlastního webu uloženo.'
                    : 'Chování webu uloženo. Přístupové údaje vyžadují APP_CREDENTIALS_KEY (base64 32 B) v .env.local — bez něj se neuloží.',
            );
        }

        return $this->redirectToRoute('channel_settings_index');
    }

    /**
     * Kanál instance používá, když je zapnutý, má přístupy nebo z něj už něco
     * dorazilo. Zbytek zůstává skrytý, dokud si ho provozovatel nepřidá.
     */
    private static function isInUse(ConnectorHealth $health): bool
    {
        return $health->persisted || $health->configured || $health->lastActivityAt !== null;
    }

    /**
     * @param array<string, string> $values
     *
     * @return FormInterface<mixed>
     */
    private function motopressForm(array $values): FormInterface
    {
        return $this->createForm(MotoPressChannelType::class, $values + $this->motopress->currentValues(), [
            'action' => $this->generateUrl('channel_settings_motopress_save'),
        ]);
    }

    /** @return FormInterface<mixed> */
    private function bookingForm(): FormInterface
    {
        return $this->createForm(BookingChannelType::class, ['bookingHotelId' => $this->extranetLink->bookingHotelId()], [
            'action' => $this->generateUrl('channel_settings_booking_save'),
        ]);
    }

    /** @param array<string, string> $params */
    private function absolute(string $route, array $params): string
    {
        return $this->generateUrl($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function saveMapping(string $petIds, string $babyCotIds, bool $push): void
    {
        // Vstup normalizujeme přes parseIds, ať se uloží čistý seznam ID.
        $this->settings->set(MotoPressSettings::KEY_PET, implode(',', MotoPressSettings::parseIds($petIds)), 'MotoPress: ID služeb „pes".');
        $this->settings->set(MotoPressSettings::KEY_BABY_COT, implode(',', MotoPressSettings::parseIds($babyCotIds)), 'MotoPress: ID služeb „dětská postýlka".');
        $this->settings->set(MotoPressSettings::KEY_PUSH, $push ? '1' : '0', 'MotoPress: posílat platby zpět.');
        $this->em->flush();
    }
}
