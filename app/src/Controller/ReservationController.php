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
use App\Controller\Concern\ParsesRequestInput;
use App\Currency\ReservationCzkPreviewResolver;
use App\Entity\Reservation;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\CleaningType;
use App\Enum\NoteType;
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
use App\Repository\AccountRepository;
use App\Repository\CleaningRepository;
use App\Repository\GuestDocumentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationReceiptRepository;
use App\Repository\ReservationRepository;
use App\Service\Cleaning\CleaningPriceList;
use App\Service\Electricity\ElectricityCostCalculator;
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
    use ParsesRequestInput;

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
        private readonly AccountRepository $accounts,
        private readonly IncomeUpserter $incomeUpserter,
        private readonly ReservationReceiptRepository $receipts,
        private readonly PaymentStatusResolver $paymentStatusResolver,
        private readonly ReservationCzkPreviewResolver $czkPreviews,
        private readonly DepositConfig $depositConfig,
        private readonly ReservationConfirmation $confirmation,
        private readonly GuestMessageTexts $guestMessageTexts,
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
            'czk_previews' => $this->czkPreviews->batch($reservations),
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
            'accounts' => $this->accounts->findOrdered(onlyActive: true),
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
            $paidAt = $this->parseDateOrNull($paidRaw);
            if ($paidAt === null) {
                $this->addFlash('warning', 'Neplatné datum vyplacení — ostatní změny uloženy.');
            } else {
                $cleaning->setPaidAt($paidAt);
            }
        } else {
            $cleaning->setPaidAt(null);
        }

        $this->em->flush();
        $this->addFlash('success', 'Úklid uložen.');

        return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
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
            // Potvrzená rezervace se známou cenou už patří do příjmů na účtech.
            $this->incomeUpserter->recompute($reservation);
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
        // Cena a provize z extranetu určují odhad výplaty na účet.
        $this->incomeUpserter->recompute($reservation);
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
}
