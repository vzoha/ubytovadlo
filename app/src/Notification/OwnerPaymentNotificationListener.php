<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Notification;

use App\Enum\OwnerNotificationType;
use App\Payment\Event\PaymentSettledEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Po spárování bankovní platby zařadí notifikaci ubytovateli „přišla platba".
 * Jen pro platby přiřazené k rezervaci (nespárované do fronty nevstupují).
 */
#[AsEventListener]
final class OwnerPaymentNotificationListener
{
    public function __construct(
        private readonly OwnerNotifier $notifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(PaymentSettledEvent $event): void
    {
        $payment = $event->payment;
        $reservation = $payment->getReservation();
        if ($reservation === null) {
            return;
        }

        $this->notifier->notify(OwnerNotificationType::PAYMENT_RECEIVED, $reservation, [
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrency(),
        ]);
        $this->em->flush();
    }
}
