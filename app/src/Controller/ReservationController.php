<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Booking\BookingExtranetParser;
use App\Cashflow\IncomeUpserter;
use App\Controller\Concern\ChecksCsrf;
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Entity\ReservationNote;
use App\Entity\ReservationReceipt;
use App\Entity\User;
use App\Enum\ActionOrigin;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\CleaningType;
use App\Enum\NoteType;
use App\Enum\ReceiptOrigin;
use App\Enum\ReservationStatus;
use App\Form\ReservationDetailsType;
use App\Form\ReservationManualType;
use App\Formatting\Money;
use App\Invoice\BalanceCalculator;
use App\Invoice\DepositConfig;
use App\Invoice\PaymentStatusResolver;
use App\Mail\GuestMessageTexts;
use App\Mail\ReservationConfirmation;
use App\Profit\ReservationProfitCalculator;
use App\Repository\CleaningRepository;
use App\Repository\GuestDocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationReceiptRepository;
use App\Repository\ReservationRepository;
use App\Service\Cleaning\CleaningPriceList;
use App\Service\Electricity\ElectricityCostCalculator;
use App\Timeline\ReservationActionExecutor;
use App\Timeline\ReservationActionPlanner;
use App\Timeline\ReservationTimelineBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReservationController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly InvoiceRepository $invoices,
        private readonly EntityManagerInterface $em,
        private readonly ElectricityCostCalculator $electricityCost,
        private readonly CleaningRepository $cleanings,
        private readonly CleaningPriceList $cleaningPriceList,
        private readonly GuestDocumentRepository $guestDocuments,
        private readonly ReservationProfitCalculator $profitCalculator,
        private readonly ReservationTimelineBuilder $timelineBuilder,
        private readonly ReservationActionPlanner $actionPlanner,
        private readonly BalanceCalculator $balanceCalculator,
        private readonly IncomeUpserter $incomeUpserter,
        private readonly ReservationReceiptRepository $receipts,
        private readonly PaymentStatusResolver $paymentStatusResolver,
        private readonly DepositConfig $depositConfig,
        private readonly ReservationConfirmation $confirmation,
        private readonly GuestMessageTexts $guestMessageTexts,
        private readonly ReservationActionExecutor $actionExecutor,
    ) {
    }

    #[Route('/rezervace', name: 'reservation_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $statusValue = $request->query->getString('status');
        $status = $statusValue !== '' ? ReservationStatus::tryFrom($statusValue) : null;

        $criteria = $status !== null ? ['status' => $status] : [];
        $reservations = $this->reservations->findBy($criteria, ['checkIn' => 'DESC']);

        return $this->render('reservation/list.html.twig', [
            'reservations' => $reservations,
            'currentStatus' => $status,
            'statuses' => ReservationStatus::cases(),
            'payment_statuses' => $this->paymentStatusResolver->batch($reservations),
        ]);
    }

    #[Route('/rezervace/nova', name: 'reservation_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $reservation = new Reservation(Channel::DIRECT, new \DateTimeImmutable('today'));
        $reservation->setGuestAddress($reservation->getGuestAddress()->withCountry('CZ'));
        $form = $this->createForm(ReservationManualType::class, $reservation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $checkOut = $reservation->getCheckOut();
            if ($checkOut !== null && $checkOut <= $reservation->getCheckIn()) {
                $form->get('checkOut')->addError(new FormError('Odjezd musí být po příjezdu.'));
            } else {
                $reservation->setPriceTotal(Money::parse($reservation->getPriceTotal()));
                // Ruční zadání = autorita nad rozdělením hostů (žádný sync to nepřepíše).
                $reservation->setGuestsSplitManually(true);
                $reservation->setStatus(ReservationStatus::CONFIRMED);
                $this->em->persist($reservation);
                $this->em->flush();
                // Plánovač dohledává existující akce podle rezervace → potřebuje její ID.
                $this->actionPlanner->planFor($reservation);
                $this->em->flush();
                $this->addFlash('success', 'Rezervace přidána.');

                return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
            }
        }

        return $this->render('reservation/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/reservation/{id}', name: 'reservation_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(Reservation $reservation): Response
    {
        $guests = $reservation->getGuestsTotal();
        $cleaningDefaults = [];
        $paidAtDefault = $reservation->getCheckIn()->format('Y-m-d');
        foreach (CleaningType::cases() as $type) {
            $cost = $this->cleaningPriceList->costFor($type, $guests);
            $cleaningDefaults[$type->value] = [
                'cost' => $cost,
                'payout' => $this->cleaningPriceList->payoutFor($type, $cost),
                'paid_at' => $paidAtDefault,
            ];
        }

        return $this->render('reservation/detail.html.twig', [
            'reservation' => $reservation,
            'invoices' => $this->invoices->findForReservation($reservation),
            'billing_modes' => BillingMode::cases(),
            'electricity_cost' => $this->electricityCost->cost($reservation),
            'cleaning' => $this->cleanings->findForReservation($reservation),
            'cleaning_types' => CleaningType::cases(),
            'cleaning_defaults' => $cleaningDefaults,
            'guest_documents' => $this->guestDocuments->findByReservation($reservation),
            'profit' => $this->profitCalculator->calculate($reservation),
            'receipts' => $this->receipts->findForReservation($reservation),
            'timeline' => $this->timelineBuilder->build($reservation),
            'balance' => $this->balanceCalculator->forReservation($reservation),
            'note_types' => NoteType::cases(),
            'deposit_applies' => $this->depositConfig->appliesTo($reservation->getBillingMode()),
            'deposit_amount' => $this->depositConfig->computeAmount($reservation->getPriceTotal()),
            'quick_messages' => $reservation->getGuestContact()->getPhone() !== null
                ? $this->guestMessageTexts->forReservation($reservation)
                : [],
        ]);
    }

    #[Route('/reservation/{id}/cleaning', name: 'reservation_set_cleaning', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function setCleaning(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'cleaning-' . $reservation->getId());

        $cleaning = $this->cleanings->findForReservation($reservation);
        if ($cleaning === null) {
            $this->addFlash('warning', 'Úklid pro tuto rezervaci neexistuje.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        $typeValue = (string) $request->request->get('type', '');
        $type = CleaningType::tryFrom($typeValue);
        if ($type === null) {
            $this->addFlash('warning', 'Neplatný typ úklidu.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }
        $cleaning->setType($type);
        $cleaning->setCostCzk((int) $request->request->get('cost_czk', 0));
        $cleaning->setPayoutCzk((int) $request->request->get('payout_czk', 0));
        $cleaning->setNote(trim((string) $request->request->get('note', '')) ?: null);

        $paidRaw = trim((string) $request->request->get('paid_at', ''));
        if ($paidRaw !== '') {
            try {
                $cleaning->setPaidAt(new \DateTimeImmutable($paidRaw));
            } catch (\Throwable) {
                $this->addFlash('warning', 'Neplatné datum vyplacení — ostatní změny uloženy.');
            }
        } else {
            $cleaning->setPaidAt(null);
        }

        $this->em->flush();
        $this->addFlash('success', 'Úklid uložen.');

        return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
    }

    #[Route('/reservation/{id}/payout', name: 'reservation_record_payout', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function recordPayout(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'payout-' . $reservation->getId());

        // Ruční výplata je OTA koncept (Airbnb/Booking) — u web rezervace host platí
        // přímo a příjem se drží z faktur, ne z výplaty.
        if (!$reservation->getChannel()->isOta()) {
            $this->addFlash('warning', 'Reálná výplata se zadává jen u OTA rezervací (Airbnb/Booking).');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        $amount = number_format((float) str_replace([' ', ','], ['', '.'], (string) $request->request->get('amount')), 2, '.', '');
        if ((float) $amount <= 0) {
            $this->addFlash('warning', 'Zadej částku výplaty.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        try {
            $dateRaw = trim((string) $request->request->get('received_on', ''));
            $receivedOn = $dateRaw !== '' ? new \DateTimeImmutable($dateRaw) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            $receivedOn = new \DateTimeImmutable('today');
        }
        $this->incomeUpserter->recordManualPayout($reservation, $amount, $receivedOn);
        $this->addFlash('success', 'Výplata zaznamenána — příjem rezervace zpřesněn.');

        return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
    }

    #[Route('/reservation/{id}/payment', name: 'reservation_record_payment', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function recordPayment(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'payment-' . $reservation->getId());

        // Ruční platba hosta je web/přímý koncept — u OTA platí host platformě
        // a reálné peníze řeší „Reálná výplata".
        if ($reservation->getChannel()->isOta()) {
            $this->addFlash('warning', 'U OTA rezervací zadej reálnou výplatu, ne platbu hosta.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        $amount = number_format((float) str_replace([' ', ','], ['', '.'], (string) $request->request->get('amount')), 2, '.', '');
        if ((float) $amount <= 0) {
            $this->addFlash('warning', 'Zadej částku platby.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        try {
            $dateRaw = trim((string) $request->request->get('received_on', ''));
            $receivedOn = $dateRaw !== '' ? new \DateTimeImmutable($dateRaw) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            $receivedOn = new \DateTimeImmutable('today');
        }

        $this->incomeUpserter->recordManualPayment($reservation, $amount, $receivedOn);
        $this->addFlash('success', 'Platba zaznamenána.');

        return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
    }

    #[Route('/reservation/payment/{id}/delete', name: 'reservation_delete_payment', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deletePayment(ReservationReceipt $receipt, Request $request): Response
    {
        $this->assertCsrf($request, 'payment-delete-' . $receipt->getId());

        if ($receipt->getOriginType() !== ReceiptOrigin::MANUAL_PAYMENT) {
            throw $this->createNotFoundException();
        }

        $reservationId = $receipt->getReservation()->getId();
        $this->em->remove($receipt);
        $this->em->flush();
        $this->addFlash('success', 'Platba smazána.');

        return $this->redirectToRoute('reservation_detail', ['id' => $reservationId]);
    }

    #[Route('/reservation/{id}/details', name: 'reservation_details', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function details(Reservation $reservation, Request $request): Response
    {
        $form = $this->createForm(ReservationDetailsType::class, $reservation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($reservation->getStatus() === ReservationStatus::NEEDS_DETAILS) {
                $reservation->setStatus(ReservationStatus::CONFIRMED);
            }
            // Po manuální editaci dospělí/děti chráníme rozdělení před přepisem z MotoPress
            // (MotoPress posílá všechny jako dospělé, ruční split se ztratí při dalším syncu).
            $reservation->setGuestsSplitManually(true);
            // Doplň automatické akce na časovou osu (idempotentní).
            $this->actionPlanner->planFor($reservation);
            $this->em->flush();
            $this->addFlash('success', 'Údaje rezervace uloženy.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }

        return $this->render('reservation/details_form.html.twig', [
            'reservation' => $reservation,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/reservation/{id}/billing-mode', name: 'reservation_set_billing_mode', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function setBillingMode(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'billing-mode-' . $reservation->getId());

        $value = (string) $request->request->get('billing_mode', '');
        $reservation->setBillingMode($value !== '' ? BillingMode::tryFrom($value) : null);
        $this->em->flush();

        $this->addFlash('success', 'Fakturační režim uložen.');

        return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
    }

    #[Route('/reservation/{id}/import-booking', name: 'reservation_import_booking', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function importBooking(Reservation $reservation, Request $request, BookingExtranetParser $parser): Response
    {
        $this->assertCsrf($request, 'import-booking-' . $reservation->getId());
        if ($reservation->getChannel() !== Channel::BOOKING) {
            throw $this->createNotFoundException();
        }

        $raw = trim((string) $request->request->get('raw', ''));
        if ($raw === '') {
            $this->addFlash('warning', 'Vlož prosím text z Booking extranetu.');

            return $this->redirectToRoute('reservation_details', ['id' => $reservation->getId()]);
        }

        $parser->parse($raw)->applyTo($reservation);
        $this->em->flush();
        $this->addFlash('success', 'Údaje naimportovány. Zkontroluj a klikni Uložit.');

        return $this->redirectToRoute('reservation_details', ['id' => $reservation->getId()]);
    }

    #[Route('/reservation/{id}/reset-checkin', name: 'reservation_reset_checkin', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resetCheckin(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'reset-checkin-' . $reservation->getId());

        $reservation->resetCheckin();
        $this->em->flush();
        $this->addFlash('success', 'Check-in znovu otevřen — host může doplnit údaje.');

        return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
    }

    #[Route('/reservation/{id}/confirm', name: 'reservation_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirm(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'confirm-' . $reservation->getId());

        $result = $this->confirmation->confirm($reservation, true);

        if ($result->emailSent) {
            $this->addFlash('success', 'Rezervace potvrzena — potvrzení odesláno hostovi.');
        } elseif ($result->statusChanged) {
            $this->addFlash('success', 'Rezervace potvrzena. ' . ($result->skipReason ?? ''));
        } else {
            $this->addFlash('warning', $result->skipReason ?? 'Rezervaci se nepodařilo potvrdit.');
        }

        return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
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
        if ($occurredRaw !== '') {
            try {
                $note->setOccurredAt(new \DateTimeImmutable($occurredRaw));
            } catch (\Throwable) {
                $this->addFlash('warning', 'Neplatné datum — použit aktuální čas.');
            }
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
        try {
            $when = $whenRaw !== '' ? new \DateTimeImmutable($whenRaw) : new \DateTimeImmutable();
        } catch (\Throwable) {
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

        $whenRaw = trim((string) $request->request->get('scheduled_for', ''));
        try {
            $action->reschedule(new \DateTimeImmutable($whenRaw));
        } catch (\Throwable) {
            $this->addFlash('warning', 'Neplatné datum.');

            return $this->redirectToRoute('reservation_detail', ['id' => $action->getReservation()->getId()]);
        }

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

        $result = $action->getResult();
        if ($action->getStatus() === ActionStatus::DONE) {
            $this->addFlash('success', $result ?? 'Zpráva odeslána hostovi.');
        } else {
            $this->addFlash('warning', $result ?? 'Zprávu se nepodařilo odeslat.');
        }

        return $this->redirectToRoute('reservation_detail', ['id' => $action->getReservation()->getId()]);
    }
}
