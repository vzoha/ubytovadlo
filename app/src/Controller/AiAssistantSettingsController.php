<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Concern\ChecksCsrf;
use App\Credential\CredentialProvider;
use App\Form\AiAssistantSettingsType;
use App\GuestMail\GuestMailSync;
use App\Mcp\McpAccessToken;
use App\Mcp\Tool\PropertyInfoTool;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * AI asistent: přístup pro AI klienta (MCP) a text, ze kterého asistent
 * odpovídá hostům.
 */
class AiAssistantSettingsController extends AbstractController
{
    use ChecksCsrf;

    /**
     * Vydaný token čeká v session jen do nejbližšího zobrazení stránky — po
     * přesměrování se ukáže jednou a obnovení stránky nový token nevydá.
     */
    private const ISSUED_TOKEN_SESSION_KEY = 'ai_assistant.issued_token';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly McpAccessToken $token,
        private readonly EntityManagerInterface $em,
        private readonly CredentialProvider $credentials,
    ) {
    }

    #[Route('/nastaveni/ai-asistent', name: 'ai_assistant_settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $form = $this->settingsForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settings->set(
                PropertyInfoTool::SETTING_KEY,
                trim((string) $form->get('propertyInfo')->getData()),
                'Informace o ubytování pro AI asistenta.',
            );
            $this->em->flush();
            $this->addFlash('success', 'Informace pro AI asistenta uloženy.');

            return $this->redirectToRoute('ai_assistant_settings_edit');
        }

        return $this->page($form, $request->getSession()->remove(self::ISSUED_TOKEN_SESSION_KEY));
    }

    #[Route('/nastaveni/ai-asistent/pristup', name: 'ai_assistant_token_issue', methods: ['POST'])]
    public function issue(Request $request): Response
    {
        $this->assertCsrf($request, 'ai_assistant_token');
        $request->getSession()->set(self::ISSUED_TOKEN_SESSION_KEY, $this->token->issue());

        return $this->redirectToRoute('ai_assistant_settings_edit');
    }

    #[Route('/nastaveni/ai-asistent/pristup/zrusit', name: 'ai_assistant_token_revoke', methods: ['POST'])]
    public function revoke(Request $request): Response
    {
        $this->assertCsrf($request, 'ai_assistant_token');
        $this->token->revoke();
        $this->addFlash('success', 'Přístup AI asistenta zrušen.');

        return $this->redirectToRoute('ai_assistant_settings_edit');
    }

    /** @return FormInterface<mixed> */
    private function settingsForm(): FormInterface
    {
        return $this->createForm(AiAssistantSettingsType::class, [
            'propertyInfo' => $this->settings->getString(PropertyInfoTool::SETTING_KEY, ''),
        ]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function page(FormInterface $form, mixed $issuedToken): Response
    {
        return $this->render('ai_assistant_settings/edit.html.twig', [
            'form' => $form->createView(),
            'tokenIssued' => $this->token->isIssued(),
            'issuedToken' => is_string($issuedToken) ? $issuedToken : null,
            'endpointUrl' => $this->generateUrl('mcp_endpoint', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'guestMailboxConfigured' => $this->credentials->guestMailbox()->isConfigured(),
            'guestMailSyncedAt' => $this->settings->getString(GuestMailSync::SYNCED_AT_KEY),
        ]);
    }
}
