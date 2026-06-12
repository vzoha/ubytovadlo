<?php

declare(strict_types=1);

namespace App\MotoPress;

use App\Entity\Reservation;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class MotoPressSync
{
    public function __construct(
        private readonly MotoPressClient $client,
        private readonly MotoPressBookingClassifier $classifier,
        private readonly MotoPressBookingMapper $mapper,
        private readonly ReservationRepository $reservations,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, scalar|array<scalar>> $query
     */
    public function sync(array $query = [], bool $dryRun = false): SyncResult
    {
        $bookings = $this->client->listBookings($query);
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($bookings as $data) {
            $mphbId = isset($data['id']) ? (string) $data['id'] : null;
            if ($mphbId === null || $mphbId === '') {
                $this->logger->warning('MotoPress booking bez id, preskakuji', ['data' => $data]);
                $skipped++;
                continue;
            }

            $checkIn = $this->parseCheckIn($data);
            if ($checkIn === null) {
                $this->logger->warning('MotoPress booking bez check_in_date, preskakuji', ['mphb_id' => $mphbId]);
                $skipped++;
                continue;
            }

            $classified = $this->classifier->classify($data);

            $reservation = $this->resolve($classified, $mphbId, $checkIn, $data);
            if ($reservation === null) {
                $skipped++;
                continue;
            }

            $isNew = $reservation->getId() === null;
            $before = $isNew ? null : $this->snapshot($reservation);

            $this->apply($reservation, $classified, $mphbId, $data);

            if ($isNew) {
                if (!$dryRun) {
                    $this->em->persist($reservation);
                }
                $created++;
            } elseif ($before !== $this->snapshot($reservation)) {
                $updated++;
            } else {
                $unchanged++;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return new SyncResult($created, $updated, $unchanged, count($bookings), $skipped);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolve(ClassifiedBooking $classified, string $mphbId, \DateTimeImmutable $checkIn, array $data): ?Reservation
    {
        return match ($classified->kind) {
            MotoPressBookingKind::WEB => $this->reservations->findByMotopressExternalId($mphbId)
                ?? $this->newReservation(Channel::WEB, $checkIn, $mphbId, $mphbId),
            MotoPressBookingKind::IMPORTED_AIRBNB => $this->resolveAirbnb($classified, $mphbId, $checkIn),
            MotoPressBookingKind::IMPORTED_BOOKING => $this->resolveBooking($mphbId, $checkIn),
            MotoPressBookingKind::IMPORTED_UNKNOWN => $this->logUnknown($mphbId, $data),
        };
    }

    private function resolveAirbnb(ClassifiedBooking $classified, string $mphbId, \DateTimeImmutable $checkIn): Reservation
    {
        $existing = $this->reservations->findByMotopressExternalId($mphbId);
        if ($existing !== null) {
            return $existing;
        }
        $code = $classified->airbnbConfirmationCode;
        if ($code !== null) {
            $matched = $this->reservations->findByExternalId(Channel::AIRBNB, $code);
            if ($matched !== null) {
                return $matched;
            }
        }

        return $this->newReservation(Channel::AIRBNB, $checkIn, $code, null);
    }

    private function resolveBooking(string $mphbId, \DateTimeImmutable $checkIn): Reservation
    {
        $existing = $this->reservations->findByMotopressExternalId($mphbId);
        if ($existing !== null) {
            return $existing;
        }
        $matched = $this->reservations->findByChannelAndCheckIn(Channel::BOOKING, $checkIn);
        if ($matched !== null) {
            return $matched;
        }

        return $this->newReservation(Channel::BOOKING, $checkIn, null, null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function logUnknown(string $mphbId, array $data): null
    {
        $this->logger->warning('MotoPress booking neznameho zdroje, preskakuji', [
            'mphb_id' => $mphbId,
            'ical_prodid' => $data['ical_prodid'] ?? null,
        ]);

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function apply(Reservation $reservation, ClassifiedBooking $classified, string $mphbId, array $data): void
    {
        if ($classified->kind === MotoPressBookingKind::WEB) {
            $this->mapper->applyWebBooking($reservation, $data);
            $reservation->setMotopressExternalId($mphbId);
            $this->applyWebBillingMode($reservation, $data);

            return;
        }

        $this->mapper->applyIcalBlock($reservation, $data);
        // Stejně jako u web větve respektujeme ruční volbu (typicky WAIVED u bezfakturačních
        // pobytů z OTA — známí, dárek). Default OTA mode nastavíme jen pokud ještě není.
        if ($reservation->getBillingMode() === null) {
            $reservation->setBillingMode(match ($classified->kind) {
                MotoPressBookingKind::IMPORTED_AIRBNB => BillingMode::AIRBNB,
                MotoPressBookingKind::IMPORTED_BOOKING => BillingMode::BOOKING_COM,
                default => null,
            });
        }
    }

    /**
     * Web rezervace: tok určen MotoPress payment.gateway_id první platby.
     *   bank   → STANDARD_WITH_DEPOSIT
     *   cash   → FKSP
     *   manual → ADMIN_BOOKING
     * MotoPress booking listing nevrací gateway_id v `payments[]` (jen id+status+amount),
     * musíme natáhnout přes /payments/{id}. Pokud rezervace nemá platbu, billingMode necháme null.
     * Pokud majitelka v UI ručně přepsala billingMode, nepřepisujeme.
     *
     * @param array<string, mixed> $data
     */
    private function applyWebBillingMode(Reservation $reservation, array $data): void
    {
        $gateway = $reservation->getMotopressPaymentGateway() ?? $this->resolveWebGateway($data);
        if ($gateway === null) {
            return;
        }

        $reservation->setMotopressPaymentGateway($gateway);

        if ($reservation->getBillingMode() !== null) {
            return;
        }

        $mode = match ($gateway) {
            'bank' => BillingMode::STANDARD_WITH_DEPOSIT,
            'cash' => BillingMode::FKSP,
            'manual' => BillingMode::ADMIN_BOOKING,
            default => null,
        };
        if ($mode !== null) {
            $reservation->setBillingMode($mode);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveWebGateway(array $data): ?string
    {
        $payments = is_array($data['payments'] ?? null) ? $data['payments'] : [];
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            // Někdy gateway_id už v embed je (např. context=edit)
            if (isset($payment['gateway_id']) && is_string($payment['gateway_id']) && $payment['gateway_id'] !== '') {
                return $payment['gateway_id'];
            }
            $paymentId = (int) ($payment['id'] ?? 0);
            if ($paymentId <= 0) {
                continue;
            }
            try {
                $full = $this->client->getPayment($paymentId);
            } catch (MotoPressApiException $e) {
                $this->logger->warning('Nepodarilo se nacist /payments z MotoPress', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);

                return null;
            }
            if (isset($full['gateway_id']) && is_string($full['gateway_id']) && $full['gateway_id'] !== '') {
                return $full['gateway_id'];
            }
        }

        return null;
    }

    private function newReservation(Channel $channel, \DateTimeImmutable $checkIn, ?string $externalId, ?string $motopressExternalId): Reservation
    {
        $reservation = new Reservation($channel, $checkIn);
        if ($externalId !== null) {
            $reservation->setExternalId($externalId);
        }
        if ($motopressExternalId !== null) {
            $reservation->setMotopressExternalId($motopressExternalId);
        }

        return $reservation;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseCheckIn(array $data): ?\DateTimeImmutable
    {
        $value = $data['check_in_date'] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function snapshot(Reservation $r): string
    {
        return serialize([
            $r->getStatus(),
            $r->getChannel(),
            $r->getExternalId(),
            $r->getMotopressExternalId(),
            $r->getCheckIn()->format('Y-m-d'),
            $r->getCheckOut()?->format('Y-m-d'),
            $r->getGuestName(),
            $r->getGuestEmail(),
            $r->getGuestPhone(),
            $r->getGuestStreet(),
            $r->getGuestCity(),
            $r->getGuestZip(),
            $r->getGuestsAdult(),
            $r->getGuestsChild(),
            $r->isGuestsSplitManually(),
            $r->getPriceTotal(),
            $r->getNotes(),
            $r->hasPet(),
            $r->getPetsNote(),
            $r->needsBabyCot(),
            $r->getBillingMode(),
            $r->getMotopressPaymentGateway(),
            $r->getBookedAt()?->getTimestamp(),
        ]);
    }
}
