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
use App\Entity\ElectricityReading;
use App\Repository\ElectricityReadingRepository;
use App\Repository\ElectricityTariffRepository;
use App\Service\Electricity\ElectricityAllocator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ElectricityController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly ElectricityReadingRepository $readings,
        private readonly ElectricityTariffRepository $tariffs,
        private readonly ElectricityAllocator $allocator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/elektrina', name: 'electricity_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('electricity/index.html.twig', [
            'readings' => array_reverse($this->readings->findAllOrdered()),
            'tariffs' => $this->tariffs->findBy([], ['validFrom' => 'DESC']),
        ]);
    }

    #[Route('/elektrina/odecet', name: 'electricity_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request, 'electricity-reading');

        $dateRaw = trim((string) $request->request->get('read_at', ''));
        $vt = (string) $request->request->get('vt_meter', '');
        $nt = (string) $request->request->get('nt_meter', '');
        $note = trim((string) $request->request->get('note', '')) ?: null;

        if ($dateRaw === '' || $vt === '' || $nt === '') {
            $this->addFlash('warning', 'Vyplň datum, VT i NT.');

            return $this->redirectToRoute('electricity_index');
        }

        try {
            $date = new \DateTimeImmutable($dateRaw);
        } catch (\Throwable) {
            $this->addFlash('warning', 'Neplatné datum.');

            return $this->redirectToRoute('electricity_index');
        }

        if ($this->readings->findOnDate($date) !== null) {
            $this->addFlash('warning', 'Pro tento den už odečet existuje. Smaž ho a založ nový.');

            return $this->redirectToRoute('electricity_index');
        }

        $reading = new ElectricityReading($date, (int) $vt, (int) $nt);
        $reading->setNote($note);
        $this->em->persist($reading);
        $this->em->flush();

        try {
            $stats = $this->allocator->rebalanceAround($date);
            $this->addFlash('success', sprintf(
                'Odečet uložen. Přepočítáno %d intervalů, %d rezervací.',
                $stats->intervals,
                $stats->reservations,
            ));
        } catch (\LogicException $e) {
            $this->addFlash('danger', 'Odečet uložen, ale alokace selhala: ' . $e->getMessage());
        }

        return $this->redirectToRoute('electricity_index');
    }

    #[Route('/elektrina/odecet/{id}/smazat', name: 'electricity_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(ElectricityReading $reading, Request $request): Response
    {
        $this->assertCsrf($request, 'electricity-delete-' . $reading->getId());

        $date = $reading->getReadAt();
        $this->em->remove($reading);
        $this->em->flush();
        $this->allocator->rebalanceAround($date);

        $this->addFlash('success', 'Odečet smazán a alokace přepočítána.');

        return $this->redirectToRoute('electricity_index');
    }

    #[Route('/elektrina/rebalance', name: 'electricity_rebalance', methods: ['POST'])]
    public function rebalance(Request $request): Response
    {
        $this->assertCsrf($request, 'electricity-rebalance');

        $stats = $this->allocator->rebalanceAll();
        $this->addFlash('success', sprintf(
            'Přepočítáno %d intervalů, alokováno %d rezervacím.',
            $stats->intervals,
            $stats->reservations,
        ));

        return $this->redirectToRoute('electricity_index');
    }
}
