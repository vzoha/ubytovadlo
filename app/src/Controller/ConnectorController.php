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
use App\Connector\ConnectorTester;
use App\Enum\ConnectorType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Zapnutí/vypnutí a otestování jednotlivých konektorů ze stránky Připojení.
 */
class ConnectorController extends AbstractController
{
    public function __construct(
        private readonly ConnectorManager $manager,
        private readonly ConnectorTester $tester,
    ) {
    }

    #[Route('/nastaveni/konektory/{type}/prepnout', name: 'connector_toggle', methods: ['POST'])]
    public function toggle(string $type, Request $request): Response
    {
        $connector = $this->resolve($type, $request);

        $enable = !$this->manager->isEnabled($connector);
        $this->manager->setEnabled($connector, $enable);
        $this->addFlash('success', sprintf('Konektor „%s" %s.', $connector->label(), $enable ? 'zapnut' : 'vypnut'));

        return $this->redirectToRoute('connection_settings_edit');
    }

    #[Route('/nastaveni/konektory/{type}/feed', name: 'connector_feed', methods: ['POST'])]
    public function feed(string $type, Request $request): Response
    {
        $connector = $this->resolve($type, $request);
        if (!$connector->supportsIcalImport()) {
            throw $this->createNotFoundException();
        }

        $url = trim((string) $request->request->get('feed_url'));
        $this->manager->setFeedUrl($connector, $url === '' ? null : $url);
        $this->addFlash('success', sprintf('Feed konektoru „%s" %s.', $connector->label(), $url === '' ? 'odebrán' : 'uložen'));

        return $this->redirectToRoute('connection_settings_edit');
    }

    #[Route('/nastaveni/konektory/{type}/webhook/obnovit', name: 'connector_webhook_regenerate', methods: ['POST'])]
    public function regenerateWebhook(string $type, Request $request): Response
    {
        $connector = $this->resolve($type, $request);
        if (!$connector->supportsWebhook()) {
            throw $this->createNotFoundException();
        }

        $this->manager->regenerateWebhookToken($connector);
        $this->addFlash('success', 'Adresa pro okamžitý import se změnila — vložte novou do WordPressu, jinak přestane chodit.');

        return $this->redirectToRoute('connection_settings_edit');
    }

    #[Route('/nastaveni/konektory/{type}/test', name: 'connector_test', methods: ['POST'])]
    public function test(string $type, Request $request): Response
    {
        $connector = $this->resolve($type, $request);

        $result = $this->tester->test($connector);
        $this->addFlash(
            $result->ok ? 'success' : 'danger',
            sprintf('%s: %s', $connector->label(), $result->message),
        );

        return $this->redirectToRoute('connection_settings_edit');
    }

    /** Ověří CSRF a přeloží řetězec na typ konektoru (jinak 404). */
    private function resolve(string $type, Request $request): ConnectorType
    {
        $connector = ConnectorType::tryFrom($type);
        if ($connector === null || !$this->isCsrfTokenValid('connector', (string) $request->request->get('_token'))) {
            throw $this->createNotFoundException();
        }

        return $connector;
    }
}
