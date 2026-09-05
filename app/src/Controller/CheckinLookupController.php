<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Checkin\CheckinLookup;
use App\Form\CheckinLookupType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Vstup pro hosta, který nemá po ruce tokenovou URL — typicky když odkaz
 * dorazil v chatu portálu. Kód rezervace plus příjmení vede na tokenovou URL,
 * která zůstává jediným nosičem oprávnění. Neúspěch nerozlišuje mezi neznámým
 * kódem a špatným příjmením a počet pokusů je omezený, aby kód nešlo uhodnout.
 */
class CheckinLookupController extends AbstractController
{
    public function __construct(
        private readonly CheckinLookup $lookup,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/checkin', name: 'checkin_lookup', methods: ['GET', 'POST'])]
    public function lookup(
        Request $request,
        RateLimiterFactoryInterface $checkinLookupIpLimiter,
        RateLimiterFactoryInterface $checkinLookupGlobalLimiter,
    ): Response {
        $form = $this->createForm(CheckinLookupType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->page($form, Response::HTTP_OK);
        }

        $perIp = $checkinLookupIpLimiter->create($request->getClientIp())->consume();
        $overall = $checkinLookupGlobalLimiter->create()->consume();
        if (!$perIp->isAccepted() || !$overall->isAccepted()) {
            return $this->page($this->withError($form, 'lookup.too_many'), Response::HTTP_TOO_MANY_REQUESTS);
        }

        /** @var array{code?: string|null, lastName?: string|null} $data */
        $data = $form->getData();
        $reservation = $this->lookup->find((string) ($data['code'] ?? ''), (string) ($data['lastName'] ?? ''));
        if ($reservation !== null) {
            return $this->redirectToRoute('checkin_index', ['token' => $reservation->getCheckinToken()]);
        }

        return $this->page($this->withError($form, 'lookup.not_found'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return FormInterface<mixed>
     */
    private function withError(FormInterface $form, string $message): FormInterface
    {
        return $form->addError(new FormError($this->translator->trans($message, [], 'checkin')));
    }

    /** @param FormInterface<mixed> $form */
    private function page(FormInterface $form, int $status): Response
    {
        return $this->render('checkin/lookup.html.twig', [
            'form' => $form->createView(),
        ], new Response('', $status));
    }
}
