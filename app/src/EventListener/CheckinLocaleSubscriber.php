<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Volba jazyka pro veřejný online check-in. Token URL zůstává čistá (posílá se
 * hostovi e-mailem), takže jazyk nedržíme v cestě, ale rozhodujeme za běhu:
 *
 *   1. `?_locale=xx` (přepínač) → ulož do session a použij,
 *   2. dřívější volba v session,
 *   3. autodetekce z hlavičky `Accept-Language` prohlížeče,
 *   4. výchozí jazyk aplikace.
 *
 * Reaguje jen na routes `checkin_*`; zbytek aplikace (admin) zůstává na výchozím
 * jazyku. Běží po RouterListener, aby znal `_route`.
 */
final class CheckinLocaleSubscriber implements EventSubscriberInterface
{
    private const SESSION_KEY = '_checkin_locale';

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with((string) $request->attributes->get('_route'), 'checkin_')) {
            return;
        }

        $requested = (string) $request->query->get('_locale', '');
        if ($requested !== '' && \in_array($requested, $this->enabledLocales, true)) {
            if ($request->hasSession()) {
                $request->getSession()->set(self::SESSION_KEY, $requested);
            }
            $request->setLocale($requested);

            return;
        }

        if ($request->hasSession()) {
            $stored = $request->getSession()->get(self::SESSION_KEY);
            if (\is_string($stored) && \in_array($stored, $this->enabledLocales, true)) {
                $request->setLocale($stored);

                return;
            }
        }

        $request->setLocale($request->getPreferredLanguage($this->enabledLocales) ?? $this->defaultLocale);
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
