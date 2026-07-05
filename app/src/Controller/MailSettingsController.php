<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Config\LogoStorage;
use App\Form\MailSettingsType;
use App\Mail\GuestMessageRenderer;
use App\Mail\MailSettingsProvider;
use App\Mail\MailThemes;
use App\Mail\SampleReservationFactory;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MailSettingsController extends AbstractController
{
    /** Pole formuláře → Setting klíč. */
    private const FIELD_KEYS = [
        'senderName' => MailSettingsProvider::SENDER_NAME,
        'senderEmail' => MailSettingsProvider::SENDER_EMAIL,
        'replyTo' => MailSettingsProvider::REPLY_TO,
        'footer' => MailSettingsProvider::FOOTER,
        'theme' => MailSettingsProvider::THEME,
        'colorPrimary' => MailSettingsProvider::COLOR_PRIMARY,
        'colorAccent' => MailSettingsProvider::COLOR_ACCENT,
    ];

    /** Ukázkové hodnoty proměnných mimo rezervaci (jen pro náhled). */
    private const SAMPLE_CONTEXT = ['invoice_number' => '2026012'];

    public function __construct(
        private readonly MailSettingsProvider $mailSettings,
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
        private readonly GuestMessageRenderer $renderer,
        private readonly SampleReservationFactory $sampleFactory,
        private readonly LogoStorage $logo,
    ) {
    }

    #[Route('/nastaveni/mail', name: 'mail_settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $form = $this->createForm(MailSettingsType::class, $this->mailSettings->currentValues());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach (self::FIELD_KEYS as $field => $key) {
                $this->settings->set($key, trim((string) $form->get($field)->getData()), 'Nastavení e-mailů hostům.');
            }
            $this->settings->set(
                MailSettingsProvider::SHOW_LOGO,
                $form->get('showLogo')->getData() ? '1' : '0',
                'Nastavení e-mailů hostům.',
            );
            $this->em->flush();
            $this->addFlash('success', 'Nastavení e-mailů uloženo.');

            return $this->redirectToRoute('mail_settings_edit');
        }

        return $this->render('mail_settings/edit.html.twig', [
            'form' => $form->createView(),
            'themes' => MailThemes::presets(),
            'hasLogo' => $this->logo->exists(),
            'logoUrl' => $this->logo->publicPath(),
        ]);
    }

    #[Route('/nastaveni/mail/nahled-paticky', name: 'mail_settings_footer_preview', methods: ['POST'])]
    public function footerPreview(Request $request): Response
    {
        $html = $this->renderer->renderFooterPreview(
            (string) $request->request->get('footer'),
            $this->sampleFactory->create(),
            self::SAMPLE_CONTEXT,
        );

        return new Response($html);
    }
}
