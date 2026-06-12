<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Enum\CleaningType;
use App\Form\CleaningSettingsType;
use App\Repository\SettingRepository;
use App\Service\Cleaning\CleaningPriceList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CleaningSettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly CleaningPriceList $prices,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/nastaveni/uklid', name: 'cleaning_settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $form = $this->createForm(CleaningSettingsType::class, $this->loadData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach (CleaningType::cases() as $type) {
                $label = trim((string) $form->get($type->value . '_label')->getData());
                $this->settings->set($this->labelKey($type), $label, 'Zobrazovaný název typu úklidu.');
                $this->settings->set($this->prices->thresholdKey($type), $this->intField($form, $type, 'threshold'), 'Ceník úklidu.');
                $this->settings->set($this->prices->smallKey($type), $this->intField($form, $type, 'small'), 'Ceník úklidu.');
                $this->settings->set($this->prices->largeKey($type), $this->intField($form, $type, 'large'), 'Ceník úklidu.');
            }
            $this->em->flush();
            $this->addFlash('success', 'Nastavení úklidu uloženo.');

            return $this->redirectToRoute('cleaning_settings_edit');
        }

        return $this->render('cleaning_settings/edit.html.twig', [
            'form' => $form->createView(),
            'types' => CleaningType::cases(),
        ]);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function loadData(): array
    {
        $data = [];
        foreach (CleaningType::cases() as $type) {
            $defaults = CleaningPriceList::defaultsFor($type);
            $data[$type->value . '_label'] = $this->settings->getString($this->labelKey($type));
            $data[$type->value . '_threshold'] = $this->settings->getInt($this->prices->thresholdKey($type), $defaults['threshold']);
            $data[$type->value . '_small'] = $this->settings->getInt($this->prices->smallKey($type), $defaults['small']);
            $data[$type->value . '_large'] = $this->settings->getInt($this->prices->largeKey($type), $defaults['large']);
        }

        return $data;
    }

    /** @param \Symfony\Component\Form\FormInterface<mixed> $form */
    private function intField(\Symfony\Component\Form\FormInterface $form, CleaningType $type, string $suffix): string
    {
        return (string) (int) $form->get($type->value . '_' . $suffix)->getData();
    }

    private function labelKey(CleaningType $type): string
    {
        return 'cleaning.' . $type->value . '.label';
    }
}
