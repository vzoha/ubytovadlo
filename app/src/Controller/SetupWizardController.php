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
use App\Connector\ConnectorManager;
use App\Form\GeneralSettingsType;
use App\Form\IssuerSettingsType;
use App\Form\MailSettingsType;
use App\Invoice\IssuerProfileProvider;
use App\Invoice\IssuerSettingsWriter;
use App\Invoice\TaxProfileConfig;
use App\Mail\MailSettingsProvider;
use App\Mail\MailSettingsWriter;
use App\Mail\MailThemes;
use App\Setup\SetupChecklist;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
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
        private readonly InstanceSettings $instance,
        private readonly InstanceSettingsWriter $instanceWriter,
        private readonly LogoStorage $logo,
        private readonly IssuerProfileProvider $issuerProvider,
        private readonly IssuerSettingsWriter $issuerWriter,
        private readonly TaxProfileConfig $taxProfile,
        private readonly MailSettingsProvider $mailSettings,
        private readonly MailSettingsWriter $mailWriter,
        private readonly SetupChecklist $checklist,
        private readonly ConnectorManager $connectors,
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
            'prevStep' => $this->prevStep($step),
            'hasLogo' => $this->logo->exists(),
            'logoUrl' => $this->logo->publicPath(),
            'pending' => $this->checklist->pending(),
            'completed' => $this->completion(),
            'themes' => MailThemes::presets(),
            'connectors' => $this->connectors->health(),
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
            'instance' => $this->instanceWriter->save($form),
            'dodavatel' => $this->issuerWriter->save($form),
            'mail' => $this->mailWriter->save($form),
            default => null,
        };
    }

    /**
     * Reálné dokončení jednotlivých kroků (podle SetupChecklist), aby stepper
     * neodškrtával kroky jen podle pořadí — přeskočený krok zůstane nedokončený.
     *
     * @return array<string, bool>
     */
    private function completion(): array
    {
        $configured = [];
        foreach ($this->checklist->items() as $item) {
            $configured[$item->key] = $item->configured;
        }

        return [
            'instance' => $configured['instance'] ?? false,
            'dodavatel' => $configured['issuer'] ?? false,
            'pripojeni' => ($configured['imap'] ?? false) || ($configured['smtp'] ?? false) || ($configured['motopress'] ?? false),
            'mail' => $configured['mail'] ?? false,
            'hotovo' => $this->checklist->pending() === [],
        ];
    }

    private function nextStep(string $step): string
    {
        $keys = array_keys(self::STEPS);
        $i = array_search($step, $keys, true);

        return $keys[$i + 1] ?? $step;
    }

    private function prevStep(string $step): ?string
    {
        $keys = array_keys(self::STEPS);
        $i = array_search($step, $keys, true);

        return $i > 0 ? $keys[$i - 1] : null;
    }
}
