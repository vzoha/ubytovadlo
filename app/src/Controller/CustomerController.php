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
use App\Customer\CustomerDuplicateFinder;
use App\Customer\CustomerEconomicsCalculator;
use App\Customer\CustomerListBuilder;
use App\Customer\CustomerListSort;
use App\Customer\CustomerMerger;
use App\Entity\Customer;
use App\Entity\Reservation;
use App\Repository\CustomerRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hosté napříč pobyty: seznam, karta hosta s poznámkou a ruční úpravy toho,
 * kdo je kdo — sloučení, oddělení pobytu, „různí lidé".
 */
class CustomerController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly ReservationRepository $reservations,
        private readonly CustomerDuplicateFinder $duplicates,
        private readonly CustomerMerger $merger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/hoste', name: 'customer_list', methods: ['GET'])]
    public function list(Request $request, CustomerListBuilder $listBuilder): Response
    {
        $search = $request->query->getString('q');
        $sort = CustomerListSort::tryFrom($request->query->getString('razeni')) ?? CustomerListSort::LAST_CHECK_IN;

        return $this->render('customer/list.html.twig', [
            'rows' => $listBuilder->build($search, $sort),
            'search' => $search,
            'sort' => $sort,
            'sorts' => CustomerListSort::cases(),
            'suggestions' => $search === '' ? $this->duplicates->findAll() : [],
        ]);
    }

    #[Route('/hoste/{id}', name: 'customer_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(Customer $customer, CustomerEconomicsCalculator $economics): Response
    {
        $stays = $this->reservations->findAllOfCustomer($customer);
        [$summary, $profits] = $economics->forStays($stays);

        return $this->render('customer/detail.html.twig', [
            'customer' => $customer,
            'stays' => $stays,
            'economics' => $summary,
            'profits' => $profits,
            'suggestions' => $this->duplicates->findFor($customer),
            'others' => array_filter(
                $this->customers->findWithStays(),
                static fn (Customer $other): bool => $other !== $customer,
            ),
        ]);
    }

    #[Route('/hoste/{id}/upravit', name: 'customer_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function edit(Customer $customer, Request $request): Response
    {
        $this->assertCsrf($request, 'customer-edit-' . $customer->getId());

        $customer->setDisplayName($request->request->getString('display_name'));
        $customer->setNote($request->request->getString('note'));
        $this->em->flush();
        $this->addFlash('success', 'Údaje hosta uloženy.');

        return $this->redirectToRoute('customer_detail', ['id' => $customer->getId()]);
    }

    #[Route('/hoste/{id}/sloucit', name: 'customer_merge', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function merge(Customer $customer, Request $request): Response
    {
        $this->assertCsrf($request, 'customer-merge-' . $customer->getId());

        $other = $this->otherCustomer($request, 'merge_id');
        $moved = $this->merger->merge($customer, $other);
        $this->addFlash('success', sprintf('Hosté sloučeni, přesunuto pobytů: %d.', $moved));

        return $this->redirectToRoute('customer_detail', ['id' => $customer->getId()]);
    }

    #[Route('/hoste/{id}/ruzni', name: 'customer_distinct', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function distinct(Customer $customer, Request $request): Response
    {
        $this->assertCsrf($request, 'customer-distinct-' . $customer->getId());

        $this->merger->markDistinct($customer, $this->otherCustomer($request, 'other_id'));
        $this->addFlash('success', 'Zapsáno jako různí lidé, návrh se už neukáže.');

        return $this->redirect($this->safeReturnPath($request) ?? $this->generateUrl('customer_detail', ['id' => $customer->getId()]));
    }

    #[Route('/hoste/pobyt/{id}/oddelit', name: 'customer_detach', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function detach(Reservation $reservation, Request $request): Response
    {
        $this->assertCsrf($request, 'customer-detach-' . $reservation->getId());

        $previous = $reservation->getCustomer();
        $customer = $this->merger->detach($reservation);
        if ($customer === null || $previous === null) {
            $this->addFlash('warning', 'Pobyt je u hosta jediný, není od čeho ho oddělit.');

            return $this->redirectToRoute('reservation_detail', ['id' => $reservation->getId()]);
        }
        $this->addFlash('success', 'Pobyt oddělen do samostatného hosta.');

        return $this->redirectToRoute('customer_detail', ['id' => $previous->getId()]);
    }

    private function otherCustomer(Request $request, string $field): Customer
    {
        $other = $this->customers->find($request->request->getInt($field));
        if ($other === null) {
            throw new NotFoundHttpException('Host nenalezen.');
        }

        return $other;
    }

    /** Návrat na seznam hostů, odkud se návrh odmítl; jiné cíle se nepouštějí. */
    private function safeReturnPath(Request $request): ?string
    {
        $path = $request->request->getString('return');

        return $path === $this->generateUrl('customer_list') ? $path : null;
    }
}
