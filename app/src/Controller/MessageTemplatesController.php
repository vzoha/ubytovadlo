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
use App\Entity\MessageTemplate;
use App\Enum\MessageKind;
use App\Form\MessageTemplateType;
use App\Mail\GuestMessageRenderer;
use App\Mail\GuestMessageSender;
use App\Mail\MessageTemplateProvider;
use App\Mail\MessageVariableResolver;
use App\Mail\SampleReservationFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MessageTemplatesController extends AbstractController
{
    /** Ukázkové hodnoty proměnných, které neplynou z rezervace (jen pro náhled/test). */
    private const SAMPLE_CONTEXT = [
        'invoice_number' => '2026012',
        'deposit_amount' => "1\u{00a0}000\u{00a0}Kč",
        'deposit_due' => '20. 7. 2026',
        'bank_account' => '1861547133/0800',
        'variable_symbol' => '1760',
    ];

    public function __construct(
        private readonly MessageTemplateProvider $templates,
        private readonly GuestMessageRenderer $renderer,
        private readonly GuestMessageSender $sender,
        private readonly SampleReservationFactory $sampleFactory,
        private readonly EntityManagerInterface $em,
        private readonly LogoStorage $logo,
    ) {
    }

    #[Route('/nastaveni/zpravy', name: 'message_templates_index', methods: ['GET'])]
    public function index(): Response
    {
        $rows = [];
        foreach (MessageKind::cases() as $kind) {
            $rows[] = ['kind' => $kind, 'template' => $this->templates->for($kind)];
        }

        return $this->render('message_templates/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/nastaveni/zpravy/{kind}', name: 'message_templates_edit', methods: ['GET', 'POST'])]
    public function edit(string $kind, Request $request): Response
    {
        $messageKind = $this->kindOr404($kind);
        $template = $this->templates->for($messageKind);

        $form = $this->createForm(MessageTemplateType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($template->getId() === null) {
                $this->em->persist($template);
            }
            $this->em->flush();
            $this->addFlash('success', 'Šablona uložena.');

            return $this->redirectToRoute('message_templates_edit', ['kind' => $kind]);
        }

        return $this->render('message_templates/edit.html.twig', [
            'kind' => $messageKind,
            'form' => $form->createView(),
            'variables' => MessageVariableResolver::variables(),
            'testRecipient' => $this->getUser()?->getUserIdentifier() ?? '',
        ]);
    }

    #[Route('/nastaveni/zpravy/{kind}/nahled', name: 'message_templates_preview', methods: ['POST'])]
    public function preview(string $kind, Request $request): Response
    {
        $messageKind = $this->kindOr404($kind);
        $transient = new MessageTemplate(
            $messageKind,
            (string) $request->request->get('subject'),
            (string) $request->request->get('body'),
        );

        $logoSrc = $this->logo->exists() ? $request->getSchemeAndHttpHost() . $this->logo->publicPath() : null;
        $rendered = $this->renderer->renderTemplate(
            $transient,
            $this->sampleFactory->create(),
            self::SAMPLE_CONTEXT,
            $logoSrc,
        );

        return new Response($rendered->html);
    }

    #[Route('/nastaveni/zpravy/{kind}/test', name: 'message_templates_test', methods: ['POST'])]
    public function test(string $kind, Request $request): Response
    {
        $messageKind = $this->kindOr404($kind);
        $recipient = trim((string) $request->request->get('email'));

        if (!$this->isCsrfTokenValid('message_template_test', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Neplatný bezpečnostní token, zkus to znovu.');
        } elseif ($recipient === '') {
            $this->addFlash('danger', 'Zadej e-mail pro testovací odeslání.');
        } else {
            try {
                $this->sender->sendTest($recipient, $messageKind, $this->sampleFactory->create(), self::SAMPLE_CONTEXT);
                $this->addFlash('success', sprintf('Testovací zpráva odeslána na %s.', $recipient));
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Odeslání selhalo: ' . $e->getMessage());
            }
        }

        return $this->redirectToRoute('message_templates_edit', ['kind' => $kind]);
    }

    private function kindOr404(string $kind): MessageKind
    {
        return MessageKind::tryFrom($kind) ?? throw $this->createNotFoundException('Neznámý druh zprávy.');
    }
}
