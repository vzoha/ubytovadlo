<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Config\UbyportSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Vypnutý modul hlášení cizinecké policii (Nastavení → Obecné) nemá v aplikaci
 * žádné stránky — schované odkazy nestačí, na adresu se dá přijít i z historie.
 */
final class UbyportModuleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UbyportSettings $settings,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route');
        if (str_starts_with($route, 'ubyport_') && !$this->settings->isEnabled()) {
            throw new NotFoundHttpException('Hlášení na Ubyport je vypnuté.');
        }
    }

    /**
     * @return array<string, array<int, array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        // Po RouterListener (32), aby `_route` už bylo k dispozici.
        return [KernelEvents::REQUEST => [['onKernelRequest', 15]]];
    }
}
