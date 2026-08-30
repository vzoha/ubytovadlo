<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller;

use App\Calendar\CalendarBuilder;
use App\Calendar\CalendarMonth;
use App\Calendar\CalendarWeekSplitter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CalendarController extends AbstractController
{
    /** Osa kreslí přesně měsíc, mřížka celé týdny okolo něj. */
    private const VIEW_TIMELINE = 'osa';
    private const VIEW_GRID = 'mesic';

    private const SESSION_VIEW = 'calendar.view';

    /** Kolik dní ukazuje pásek obsazenosti na přehledu. */
    private const STRIP_DAYS = 14;

    public function __construct(
        private readonly CalendarBuilder $builder,
        private readonly CalendarWeekSplitter $splitter,
    ) {
    }

    #[Route('/kalendar', name: 'calendar_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $today = new \DateTimeImmutable('today');
        $month = CalendarMonth::fromString($request->query->get('mesic'), $today);
        $view = $this->resolveView($request);

        $calendar = $this->builder->build(
            $view === self::VIEW_GRID ? $month->gridRange() : $month->timelineRange(),
            $today,
            $month,
        );

        return $this->render('calendar/index.html.twig', [
            'month' => $month,
            'view' => $view,
            'calendar' => $calendar,
            'weeks' => $view === self::VIEW_GRID ? $this->splitter->split($calendar) : [],
            'today' => $today,
        ]);
    }

    /**
     * Pásek nejbližších dnů pro přehled. Vykresluje se přes `render(controller(...))`,
     * takže přehled nemusí vědět, z čeho se kalendář skládá.
     */
    public function strip(): Response
    {
        $today = new \DateTimeImmutable('today');

        return $this->render('calendar/_strip.html.twig', [
            'calendar' => $this->builder->buildNextDays(self::STRIP_DAYS, $today),
        ]);
    }

    /** Volba pohledu z URL se pamatuje na sezení, ať se kalendář otevírá tak, jak ho majitel nechal. */
    private function resolveView(Request $request): string
    {
        $session = $request->getSession();
        $requested = $request->query->get('pohled');
        if ($requested === self::VIEW_TIMELINE || $requested === self::VIEW_GRID) {
            $session->set(self::SESSION_VIEW, $requested);

            return $requested;
        }

        $stored = $session->get(self::SESSION_VIEW);

        return $stored === self::VIEW_GRID ? self::VIEW_GRID : self::VIEW_TIMELINE;
    }
}
