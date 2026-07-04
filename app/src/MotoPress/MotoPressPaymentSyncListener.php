<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\MotoPress;

use App\Payment\Event\PaymentSettledEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Volitelný konektor: po spárování platby u nás označí odpovídající platbu v MotoPressu
 * jako "completed" (MotoPress si pak rezervaci sám potvrdí). Push zpět do MotoPressu je
 * vedlejší efekt — Ubytovadlo zůstává source of truth a instance bez MotoPressu tenhle
 * listener prostě nemá zapnutý (MOTOPRESS_PUSH_PAYMENTS=0).
 *
 * Selhání MotoPressu nikdy neshodí zpracování platby — jen se zaloguje.
 */
#[AsEventListener]
class MotoPressPaymentSyncListener
{
    private const COMPLETED = 'completed';

    public function __construct(
        private readonly MotoPressClient $client,
        private readonly LoggerInterface $logger,
        private readonly MotoPressSettings $settings,
    ) {
    }

    public function __invoke(PaymentSettledEvent $event): void
    {
        if (!$this->settings->pushPayments()) {
            return;
        }

        $bookingId = $event->payment->getReservation()?->getMotopressExternalId();
        // Jen webové rezervace z MotoPressu (číselné booking ID); OTA/ruční přeskočíme.
        if ($bookingId === null || !ctype_digit($bookingId)) {
            return;
        }
        $paidAmount = $this->normalizeAmount($event->payment->getAmount());

        try {
            $booking = $this->client->getBooking((int) $bookingId);
            $payments = is_array($booking['payments'] ?? null) ? $booking['payments'] : [];
            foreach ($payments as $payment) {
                if (!is_array($payment)) {
                    continue;
                }
                $id = isset($payment['id']) && is_numeric($payment['id']) ? (int) $payment['id'] : 0;
                $status = is_string($payment['status'] ?? null) ? $payment['status'] : '';
                $amount = $this->normalizeAmount($payment['amount'] ?? null);
                // Označíme jen platbu odpovídající přijaté částce — u zálohy + doplatku
                // by jinak "completed" dostal i nesouvisející doplatek.
                if ($id <= 0 || $status === self::COMPLETED || $amount !== $paidAmount) {
                    continue;
                }
                $this->client->updatePaymentStatus($id, self::COMPLETED);
                $this->logger->info('MotoPress platba označena jako completed', [
                    'payment_id' => $id,
                    'booking_id' => $bookingId,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Push stavu platby do MotoPressu selhal', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function normalizeAmount(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
