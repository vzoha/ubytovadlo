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
use App\Entity\QuickMessage;
use App\Form\QuickMessageType;
use App\Mail\MessageVariableResolver;
use App\Repository\QuickMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class QuickMessageController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly QuickMessageRepository $messages,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/nastaveni/rychle-zpravy', name: 'quick_message_index', methods: ['GET'])]
    public function index(): Response
    {
        $newForm = $this->createForm(QuickMessageType::class, new QuickMessage('', ''), [
            'action' => $this->generateUrl('quick_message_new'),
        ]);

        return $this->render('quick_message/index.html.twig', [
            'messages' => $this->messages->findOrdered(),
            'newForm' => $newForm->createView(),
            'variables' => MessageVariableResolver::plainTextVariables(),
        ]);
    }

    #[Route('/nastaveni/rychle-zpravy/nova', name: 'quick_message_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $message = new QuickMessage('', '');
        $form = $this->createForm(QuickMessageType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $message->setSortOrder($this->messages->nextSortOrder());
            $this->em->persist($message);
            $this->em->flush();
            $this->addFlash('success', 'Rychlá zpráva přidána.');

            return $this->redirectToRoute('quick_message_index');
        }

        return $this->render('quick_message/form.html.twig', [
            'form' => $form->createView(),
            'message' => $message,
            'variables' => MessageVariableResolver::plainTextVariables(),
        ]);
    }

    #[Route('/nastaveni/rychle-zpravy/{id}', name: 'quick_message_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(QuickMessage $message, Request $request): Response
    {
        $form = $this->createForm(QuickMessageType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Rychlá zpráva uložena.');

            return $this->redirectToRoute('quick_message_index');
        }

        return $this->render('quick_message/form.html.twig', [
            'form' => $form->createView(),
            'message' => $message,
            'variables' => MessageVariableResolver::plainTextVariables(),
        ]);
    }

    #[Route('/nastaveni/rychle-zpravy/{id}/smazat', name: 'quick_message_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(QuickMessage $message, Request $request): Response
    {
        $this->assertCsrf($request, 'quick-message-delete-' . $message->getId());

        $this->em->remove($message);
        $this->em->flush();
        $this->addFlash('success', 'Rychlá zpráva smazána.');

        return $this->redirectToRoute('quick_message_index');
    }

    #[Route('/nastaveni/rychle-zpravy/{id}/posun', name: 'quick_message_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function move(QuickMessage $message, Request $request): Response
    {
        $this->assertCsrf($request, 'quick-message-move-' . $message->getId());

        $direction = (string) $request->request->get('direction');
        $ordered = $this->messages->findOrdered();
        $index = array_search($message, $ordered, true);

        if ($index !== false) {
            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
            if (isset($ordered[$swapWith])) {
                $neighbour = $ordered[$swapWith];
                $order = $message->getSortOrder();
                $message->setSortOrder($neighbour->getSortOrder());
                $neighbour->setSortOrder($order);
                $this->em->flush();
            }
        }

        return $this->redirectToRoute('quick_message_index');
    }
}
