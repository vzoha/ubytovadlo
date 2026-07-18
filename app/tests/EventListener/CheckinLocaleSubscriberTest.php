<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\CheckinLocaleSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CheckinLocaleSubscriberTest extends TestCase
{
    private const ENABLED = ['cs', 'en', 'de', 'pl'];

    /**
     * @param array<string, string> $query
     * @param array<string, string> $server
     */
    private function dispatch(string $route, array $query = [], array $server = [], ?string $sessionLocale = null): Request
    {
        $request = new Request($query, [], ['_route' => $route], [], [], $server);
        $session = new Session(new MockArraySessionStorage());
        if ($sessionLocale !== null) {
            $session->set('_checkin_locale', $sessionLocale);
        }
        $request->setSession($session);

        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        (new CheckinLocaleSubscriber(self::ENABLED, 'cs'))->onKernelRequest($event);

        return $request;
    }

    public function testQueryParamWinsAndIsStored(): void
    {
        $request = $this->dispatch('checkin_index', ['_locale' => 'en']);

        self::assertSame('en', $request->getLocale());
        self::assertSame('en', $request->getSession()->get('_checkin_locale'));
    }

    public function testStoredSessionUsedWhenNoQuery(): void
    {
        $request = $this->dispatch('checkin_host_new', [], [], 'de');

        self::assertSame('de', $request->getLocale());
    }

    public function testAcceptLanguageUsedWhenNoQueryNoSession(): void
    {
        $request = $this->dispatch('checkin_index', [], ['HTTP_ACCEPT_LANGUAGE' => 'de,en;q=0.7']);

        self::assertSame('de', $request->getLocale());
    }

    public function testInvalidQueryFallsThroughToAcceptLanguage(): void
    {
        $request = $this->dispatch('checkin_index', ['_locale' => 'ru'], ['HTTP_ACCEPT_LANGUAGE' => 'pl']);

        self::assertSame('pl', $request->getLocale());
    }

    public function testNonCheckinRouteIsIgnored(): void
    {
        $request = $this->dispatch('dashboard', ['_locale' => 'en']);

        // Locale zůstává výchozí ('en' request default), subscriber ho nepřepsal.
        self::assertSame('en', $request->getDefaultLocale());
        self::assertNull($request->getSession()->get('_checkin_locale'));
    }

    public function testDefaultCzechWithoutAnySignal(): void
    {
        $request = $this->dispatch('checkin_thanks');

        self::assertSame('cs', $request->getLocale());
    }
}
