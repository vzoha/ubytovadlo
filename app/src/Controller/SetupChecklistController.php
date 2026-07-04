<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Setup\SetupChecklist;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Skrytí / obnovení položek onboarding checklistu na dashboardu.
 */
class SetupChecklistController extends AbstractController
{
    public function __construct(
        private readonly SetupChecklist $checklist,
    ) {
    }

    #[Route('/checklist/{key}/skryt', name: 'setup_checklist_dismiss', methods: ['POST'])]
    public function dismiss(string $key, Request $request): Response
    {
        if ($this->isCsrfTokenValid('setup_checklist', (string) $request->request->get('_token'))) {
            $this->checklist->dismiss($key);
        }

        return $this->redirectToRoute('dashboard');
    }

    #[Route('/checklist/obnovit', name: 'setup_checklist_restore', methods: ['POST'])]
    public function restore(Request $request): Response
    {
        if ($this->isCsrfTokenValid('setup_checklist', (string) $request->request->get('_token'))) {
            $this->checklist->restore();
        }

        return $this->redirectToRoute('dashboard');
    }
}
