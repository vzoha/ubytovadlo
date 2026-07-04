<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Config\InstanceSettings;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Odkazy generované v CLI (cron maily hostům / notifikace) berou host + schéma
 * z router RequestContextu, který se v non-HTTP kontextu plní z env DEFAULT_URI.
 * Aby platila adresa nastavená v UI (/nastaveni/obecne → app.base_url), přepíšeme
 * kontext z ní hned na začátku každého příkazu. Bez settingu zůstává env fallback.
 */
final class RouterContextConsoleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly InstanceSettings $settings,
        private readonly RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => 'onCommand'];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $baseUrl = $this->settings->baseUrl();
        if ($baseUrl === '') {
            return;
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['host'])) {
            return;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $context = $this->router->getContext();
        $context->setScheme($scheme);
        $context->setHost($parts['host']);
        $context->setBaseUrl($parts['path'] ?? '');
        if (isset($parts['port'])) {
            if ($scheme === 'https') {
                $context->setHttpsPort($parts['port']);
            } else {
                $context->setHttpPort($parts['port']);
            }
        }
    }
}
