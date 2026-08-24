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
use App\Controller\Concern\ParsesRequestInput;
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Entity\ReservationNote;
use App\Entity\User;
use App\Enum\ActionOrigin;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\NoteType;
use App\Timeline\ReservationActionExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Časová osa rezervace — poznámky ubytovatele a naplánované akce (připomínky
 * a zprávy hostovi). Zápis do osy z UI; plánování a odesílání na cronu drží
 * `App\Timeline`.
 */
class ReservationTimelineController extends AbstractController
{
    use ChecksCsrf;
    use ParsesRequestInput;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationActionExecutor $actionExecutor,
    ) {
    }

    #[Route('/reservation/{id}/note', name: 'reservation_add_note', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addNote(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'note-' . $reservation->getId());

        $body = trim((string) $request->request->get('body', ''));
        if ($body === '') {
            $this->addFlash('warning', 'Poznámka je prázdná.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        $type = NoteType::tryFrom((string) $request->request->get('type', '')) ?? NoteType::POZNAMKA;
        $note = new ReservationNote($reservation, $type, $body);

        $occurredRaw = trim((string) $request->request->get('occurred_at', ''));
        $occurredAt = $occurredRaw === '' ? null : $this->parseDateOrNull($occurredRaw);
        if ($occurredRaw !== '' && $occurredAt === null) {
            $this->addFlash('warning', 'Neplatné datum — použit aktuální čas.');
        }
        if ($occurredAt !== null) {
            $note->setOccurredAt($occurredAt);
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            $note->setAuthor($user);
        }

        $this->em->persist($note);
        $this->em->flush();
        $this->addFlash('success', 'Poznámka přidána.');

        return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
    }

    #[Route('/reservation/{id}/action', name: 'reservation_add_action', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addAction(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'action-' . $reservation->getId());

        // Ručně lze přidat jen připomínku nebo ad-hoc zprávu hostovi.
        $type = ActionType::tryFrom((string) $request->request->get('type', '')) ?? ActionType::CUSTOM_REMINDER;
        if (!in_array($type, [ActionType::CUSTOM_REMINDER, ActionType::CUSTOM_MESSAGE], true)) {
            $type = ActionType::CUSTOM_REMINDER;
        }

        $text = trim((string) $request->request->get('text', ''));
        if ($text === '') {
            $this->addFlash('warning', 'Vyplň text připomínky.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        $whenRaw = trim((string) $request->request->get('scheduled_for', ''));
        $when = $whenRaw !== '' ? $this->parseDateOrNull($whenRaw) : new \DateTimeImmutable();
        if ($when === null) {
            $this->addFlash('warning', 'Neplatné datum termínu.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        $action = new ReservationAction($reservation, $type, $when, ActionOrigin::MANUAL);
        $action->setPayload(['text' => $text]);
        $this->em->persist($action);
        $this->em->flush();
        $this->addFlash('success', 'Akce naplánována.');

        return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
    }

    #[Route('/reservation/action/{id}/reschedule', name: 'reservation_action_reschedule', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rescheduleAction(ReservationAction $action, Request $request): Response
    {
        $this->assertCsrf($request, 'action-edit-' . $action->getId());

        // Prázdný vstup znamená „teď" (`new \DateTimeImmutable('')`), tak to drž.
        $whenRaw = trim((string) $request->request->get('scheduled_for', ''));
        $when = $whenRaw !== '' ? $this->parseDateOrNull($whenRaw) : new \DateTimeImmutable();
        if ($when === null) {
            $this->addFlash('warning', 'Neplatné datum.');

            return $this->redirectToRoute('reservation_detail', ['id' => $action->getReservation()->getId()]);
        }

        $action->reschedule($when);
        $this->em->flush();
        $this->addFlash('success', 'Akce odložena.');

        return $this->redirectToRoute('reservation_detail', ['id' => $action->getReservation()->getId()]);
    }

    #[Route('/reservation/action/{id}/cancel', name: 'reservation_action_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancelAction(ReservationAction $action, Request $request): Response
    {
        $this->assertCsrf($request, 'action-edit-' . $action->getId());

        $action->cancel();
        $this->em->flush();
        $this->addFlash('success', 'Akce zrušena.');

        return $this->redirectToRoute('reservation_detail', ['id' => $action->getReservation()->getId()]);
    }

    #[Route('/reservation/action/{id}/done', name: 'reservation_action_done', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markActionDone(ReservationAction $action, Request $request): Response
    {
        $this->assertCsrf($request, 'action-edit-' . $action->getId());

        $action->markDone('Vyřízeno ručně.');
        $this->em->flush();
        $this->addFlash('success', 'Akce označena jako hotová.');

        return $this->redirectToRoute('reservation_detail', ['id' => $action->getReservation()->getId()]);
    }

    #[Route('/reservation/action/{id}/send', name: 'reservation_action_send', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function sendActionMessage(ReservationAction $action, Request $request): Response
    {
        $this->assertCsrf($request, 'action-edit-' . $action->getId());

        if ($this->actionExecutor->sendNow($action)) {
            $this->em->flush();
        }

        $sent = $action->getStatus() === ActionStatus::DONE;
        $this->addFlash(
            $sent ? 'success' : 'warning',
            $action->getResult() ?? ($sent ? 'Zpráva odeslána hostovi.' : 'Zprávu se nepodařilo odeslat.'),
        );

        return $this->redirectToRoute('reservation_detail', ['id' => $action->getReservation()->getId()]);
    }
}
