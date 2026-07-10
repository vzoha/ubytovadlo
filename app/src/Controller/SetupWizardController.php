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
use App\Config\LogoStorage;
use App\Form\GeneralSettingsType;
use App\Form\IssuerSettingsType;
use App\Form\MailSettingsType;
use App\Invoice\IssuerProfileProvider;
use App\Invoice\TaxProfileConfig;
use App\Mail\MailSettingsProvider;
use App\Repository\SettingRepository;
use App\Setup\SetupChecklist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Průvodce prvotním nastavením — provede ubytovatele klíčovými kroky v pořadí:
 * identita instance → dodavatel a daňový profil → připojení → e-maily → souhrn.
 * Formulářové kroky znovupoužívají tytéž form typy i providery jako samostatné
 * stránky nastavení; „Připojení" (šifrované přístupy) odkazuje na svou stránku.
 *
 * Pod prefixem /nastaveni → gated na ROLE_ADMIN (security.yaml access_control).
 */
class SetupWizardController extends AbstractController
{
    /** Kroky v pořadí: klíč => [label, popis]. */
    private const STEPS = [
        'instance' => ['Aplikace', 'Název a veřejná adresa instance, logo.'],
        'dodavatel' => ['Dodavatel', 'Fakturační identita, banka a daňový profil.'],
        'pripojeni' => ['Připojení', 'Automatizační schránka, web (MotoPress), kanály.'],
        'mail' => ['E-maily', 'Odesílatel a vzhled zpráv hostům.'],
        'hotovo' => ['Hotovo', 'Souhrn nastavení.'],
    ];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
        private readonly InstanceSettings $instance,
        private readonly LogoStorage $logo,
        private readonly IssuerProfileProvider $issuerProvider,
        private readonly TaxProfileConfig $taxProfile,
        private readonly MailSettingsProvider $mailSettings,
        private readonly SetupChecklist $checklist,
    ) {
    }

    #[Route('/nastaveni/pruvodce', name: 'setup_wizard', methods: ['GET'])]
    public function start(): Response
    {
        return $this->redirectToRoute('setup_wizard_step', ['step' => array_key_first(self::STEPS)]);
    }

    #[Route('/nastaveni/pruvodce/{step}', name: 'setup_wizard_step', methods: ['GET', 'POST'], requirements: ['step' => 'instance|dodavatel|pripojeni|mail|hotovo'])]
    public function step(string $step, Request $request): Response
    {
        $form = $this->buildForm($step);
        if ($form !== null) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $this->save($step, $form);
                $this->addFlash('success', 'Uloženo.');

                return $this->redirectToRoute('setup_wizard_step', ['step' => $this->nextStep($step)]);
            }
        }

        return $this->render('setup_wizard/step.html.twig', [
            'steps' => self::STEPS,
            'step' => $step,
            'stepIndex' => array_search($step, array_keys(self::STEPS), true),
            'form' => $form?->createView(),
            'nextStep' => $this->nextStep($step),
            'hasLogo' => $this->logo->exists(),
            'logoUrl' => $this->logo->publicPath(),
            'pending' => $this->checklist->pending(),
        ]);
    }

    /** @return FormInterface<mixed>|null */
    private function buildForm(string $step): ?FormInterface
    {
        return match ($step) {
            'instance' => $this->createForm(GeneralSettingsType::class, $this->instance->currentValues()),
            'dodavatel' => $this->createForm(IssuerSettingsType::class, $this->issuerProvider->currentValues() + [
                'taxProfile' => $this->taxProfile->current(),
            ]),
            'mail' => $this->createForm(MailSettingsType::class, $this->mailSettings->currentValues()),
            default => null,
        };
    }

    /** @param FormInterface<mixed> $form */
    private function save(string $step, FormInterface $form): void
    {
        match ($step) {
            'instance' => $this->saveInstance($form),
            'dodavatel' => $this->saveIssuer($form),
            'mail' => $this->saveMail($form),
            default => null,
        };
    }

    /** @param FormInterface<mixed> $form */
    private function saveInstance(FormInterface $form): void
    {
        $this->settings->set(InstanceSettings::KEY_BRAND_NAME, trim((string) $form->get('brandName')->getData()), 'Název instance (brand).');
        $this->settings->set(InstanceSettings::KEY_BASE_URL, trim((string) $form->get('baseUrl')->getData()), 'Veřejná adresa aplikace pro odkazy v e-mailech.');
        $this->em->flush();

        $logoFile = $form->get('logoFile')->getData();
        if ($logoFile instanceof UploadedFile) {
            $this->logo->store($logoFile);
        }
    }

    /** @param FormInterface<mixed> $form */
    private function saveIssuer(FormInterface $form): void
    {
        foreach (IssuerProfileProvider::KEYS as $field => $key) {
            $this->settings->set($key, trim((string) $form->get($field)->getData()), 'Dodavatel na faktuře.');
        }
        $this->settings->set(TaxProfileConfig::KEY, $form->get('taxProfile')->getData()->value, 'Daňový profil dodavatele.');
        $this->em->flush();
    }

    /** @param FormInterface<mixed> $form */
    private function saveMail(FormInterface $form): void
    {
        $map = [
            'senderName' => MailSettingsProvider::SENDER_NAME,
            'senderEmail' => MailSettingsProvider::SENDER_EMAIL,
            'replyTo' => MailSettingsProvider::REPLY_TO,
            'footer' => MailSettingsProvider::FOOTER,
            'theme' => MailSettingsProvider::THEME,
            'colorPrimary' => MailSettingsProvider::COLOR_PRIMARY,
            'colorAccent' => MailSettingsProvider::COLOR_ACCENT,
        ];
        foreach ($map as $field => $key) {
            $this->settings->set($key, trim((string) $form->get($field)->getData()), 'Nastavení e-mailů hostům.');
        }
        $this->settings->set(MailSettingsProvider::SHOW_LOGO, $form->get('showLogo')->getData() ? '1' : '0', 'Nastavení e-mailů hostům.');
        $this->em->flush();
    }

    private function nextStep(string $step): string
    {
        $keys = array_keys(self::STEPS);
        $i = array_search($step, $keys, true);

        return $keys[$i + 1] ?? $step;
    }
}
