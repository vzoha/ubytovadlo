<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Enum\OwnerNotificationMode;
use App\Enum\OwnerNotificationType;
use App\Form\OwnerNotificationSettingsType;
use App\Notification\OwnerNotificationSettingsProvider;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Nastavení e-mailových notifikací ubytovateli: adresa příjemce a per-typ režim
 * doručení (okamžitě / denní souhrn / vypnuto). Ukládá do Setting klíčů
 * `notifications.owner.*`.
 */
class OwnerNotificationSettingsController extends AbstractController
{
    private const NOTE = 'Nastavení notifikací ubytovateli.';

    public function __construct(
        private readonly OwnerNotificationSettingsProvider $notifications,
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/nastaveni/notifikace', name: 'owner_notification_settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $form = $this->createForm(OwnerNotificationSettingsType::class, $this->formData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settings->set(OwnerNotificationSettingsProvider::RECIPIENT, trim((string) $form->get('email')->getData()), self::NOTE);
            foreach (OwnerNotificationType::cases() as $type) {
                $mode = $form->get($type->value)->getData();
                \assert($mode instanceof OwnerNotificationMode);
                $this->settings->set(OwnerNotificationSettingsProvider::modeKey($type), $mode->value, self::NOTE);
            }
            $this->em->flush();
            $this->addFlash('success', 'Nastavení notifikací uloženo.');

            return $this->redirectToRoute('owner_notification_settings_edit');
        }

        return $this->render('owner_notification_settings/edit.html.twig', [
            'form' => $form->createView(),
            'types' => OwnerNotificationType::cases(),
        ]);
    }

    /**
     * Předvyplnění formuláře: adresa + režim pro každý typ.
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        $current = $this->notifications->currentValues();
        $data = ['email' => $current['email']];
        foreach (OwnerNotificationType::cases() as $type) {
            $data[$type->value] = $current['modes'][$type->value];
        }

        return $data;
    }
}
