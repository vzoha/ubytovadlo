<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Mail\MessageLocales;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Jazyk, který si host přepnul v check-inu, si rezervace pamatuje — e-maily od
 * nás pak chodí v něm a jeho volba má přednost před volbou ubytovatele.
 *
 * Zapisuje se jen vědomé přepnutí (CheckinLocaleSubscriber::SWITCH_ATTRIBUTE),
 * ne jazyk odhadnutý z prohlížeče: Čech s anglickým systémem by jinak dostával
 * anglické zprávy.
 */
final class CheckinGuestLocaleListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->get(CheckinLocaleSubscriber::SWITCH_ATTRIBUTE) !== true) {
            return;
        }

        $token = $request->attributes->get('token');
        if (!\is_string($token)) {
            return;
        }

        $reservation = $this->reservations->findOneBy(['checkinToken' => $token]);
        if ($reservation === null) {
            return;
        }

        $locale = MessageLocales::fromInterfaceLocale($request->getLocale());
        if ($reservation->getGuestLocale() === $locale && $reservation->getGuestLocaleChosenAt() !== null) {
            return;
        }

        $reservation->chooseGuestLocale($locale, new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * @return array<string, array<int, array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        // Po CheckinLocaleSubscriber (15), který jazyk rozhodne a označí přepnutí.
        return [KernelEvents::REQUEST => [['onKernelRequest', 10]]];
    }
}
