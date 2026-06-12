<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Channel;
use App\Profit\YearEconomicsBuilder;
use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Roční ekonomický přehled — náhrada za ruční tabulkovou evidenci:
 * tabulka rezervací s rozpadem výdajů a ziskem + součty dle kanálu,
 * rozdělené na uskutečněné a očekávané pobyty.
 */
class EconomicsController extends AbstractController
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly YearEconomicsBuilder $economics,
    ) {
    }

    #[Route('/ekonomika/{year}', name: 'economics_overview', methods: ['GET'], requirements: ['year' => '\d{4}'])]
    public function overview(?int $year = null): Response
    {
        $today = new \DateTimeImmutable('today');
        $year ??= (int) $today->format('Y');

        $economics = $this->economics->build($year, $today);

        return $this->render('economics/overview.html.twig', [
            'year' => $year,
            'years' => $this->reservations->findDistinctCheckInYears(),
            'today' => $today,
            'channels' => Channel::cases(),
        ] + $economics);
    }
}
