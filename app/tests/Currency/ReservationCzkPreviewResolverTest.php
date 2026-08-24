<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Currency;

use App\Currency\ReservationCzkPreviewResolver;
use App\Entity\Cleaning;
use App\Entity\Embeddable\VatReverseCharge;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\CzkRateSource;
use App\Enum\InvoiceType;
use App\Enum\ReservationStatus;
use App\Vat\CnbExchangeRateClient;
use App\Vat\CnbRate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ReservationCzkPreviewResolverTest extends KernelTestCase
{
    private const TODAY = '2026-08-24';

    private EntityManagerInterface $em;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->container = static::getContainer();

        $em = $this->container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        // Úklid ke každé rezervaci zakládá listener — smazat, ať nezůstane viset.
        $this->em->createQuery('DELETE FROM ' . Cleaning::class . ' c')->execute();
        $this->em->createQuery('DELETE FROM ' . InvoiceLine::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
    }

    public function testCzkReservationHasNoPreview(): void
    {
        $this->expectNoCnbCall();
        $r = $this->makeReservation('4455.00', 'CZK');
        $this->em->flush();

        self::assertSame([], $this->batch($r));
    }

    public function testReservationWithoutPriceHasNoPreview(): void
    {
        $this->expectNoCnbCall();
        $r = $this->makeReservation(null, 'EUR');
        $this->em->flush();

        self::assertSame([], $this->batch($r));
    }

    public function testStoredCnbRateIsUsedAndNotAnEstimate(): void
    {
        $this->expectNoCnbCall();

        $r = $this->makeReservation('100.00', 'EUR');
        $r->setCommissionAmount('15.00')->setCommissionCurrency('EUR');
        $r->setVatReverseCharge(new VatReverseCharge(
            cnbRate: '24.36000000',
            cnbRateDate: new \DateTimeImmutable('2026-04-16'),
        ));
        $this->em->flush();

        $preview = $this->batch($r)[(int) $r->getId()];

        self::assertSame('2436.00', $preview->amountCzk);
        self::assertSame(CzkRateSource::RESERVATION, $preview->source);
        self::assertSame('2026-04-16', $preview->rateDate?->format('Y-m-d'));
        self::assertFalse($preview->isEstimate());
    }

    public function testInvoiceRateWinsOverStoredCnbRate(): void
    {
        $this->expectNoCnbCall();
        $r = $this->makeReservation('100.00', 'EUR');
        $r->setCommissionAmount('15.00')->setCommissionCurrency('EUR');
        $r->setVatReverseCharge(new VatReverseCharge(cnbRate: '24.36000000'));
        $invoice = $this->makeInvoice($r);
        $invoice->setOriginalCurrency('EUR');
        $invoice->setExchangeRate('25.10000000');
        $invoice->setExchangeRateDate(new \DateTimeImmutable('2026-04-20'));
        $this->em->flush();

        $preview = $this->batch($r)[(int) $r->getId()];

        self::assertSame('2510.00', $preview->amountCzk);
        self::assertSame(CzkRateSource::INVOICE, $preview->source);
        self::assertSame('2026-04-20', $preview->rateDate?->format('Y-m-d'));
    }

    public function testRateInAnotherCurrencyIsNotUsedForPrice(): void
    {
        // Provize v CZK znamená, že uložený kurz o ceně v EUR nic neříká.
        $this->useDailyRate(24.9);

        $r = $this->makeReservation('100.00', 'EUR');
        $r->setCommissionAmount('400.00')->setCommissionCurrency('CZK');
        $r->setVatReverseCharge(new VatReverseCharge(cnbRate: '24.36000000'));
        $this->em->flush();

        $preview = $this->batch($r)[(int) $r->getId()];

        self::assertSame(CzkRateSource::DAILY, $preview->source);
        self::assertSame('2490.00', $preview->amountCzk);
    }

    public function testDailyRateIsMarkedAsEstimate(): void
    {
        // Jeden dotaz na ČNB pro celý seznam, ne jeden na rezervaci.
        $cnb = $this->createMock(CnbExchangeRateClient::class);
        $cnb->expects(self::once())
            ->method('getRate')
            ->with('EUR', new \DateTimeImmutable(self::TODAY))
            ->willReturn(new CnbRate('EUR', 24.9, new \DateTimeImmutable('2026-08-22')));
        $this->useCnb($cnb);

        $first = $this->makeReservation('100.00', 'EUR');
        $second = $this->makeReservation('200.00', 'EUR');
        $this->em->flush();

        $previews = $this->batch($first, $second);

        self::assertSame('2490.00', $previews[(int) $first->getId()]->amountCzk);
        self::assertSame('4980.00', $previews[(int) $second->getId()]->amountCzk);
        self::assertTrue($previews[(int) $first->getId()]->isEstimate());
        self::assertSame('2026-08-22', $previews[(int) $first->getId()]->rateDate?->format('Y-m-d'));
    }

    public function testUnavailableCnbApiLeavesReservationWithoutPreview(): void
    {
        $cnb = $this->createStub(CnbExchangeRateClient::class);
        $cnb->method('getRate')->willThrowException(new \RuntimeException('CNB down'));
        $this->useCnb($cnb);

        $r = $this->makeReservation('100.00', 'EUR');
        $this->em->flush();

        self::assertSame([], $this->batch($r));
    }

    /**
     * @return array<int, \App\Currency\CzkPreview>
     */
    private function batch(Reservation ...$reservations): array
    {
        $resolver = $this->container->get(ReservationCzkPreviewResolver::class);
        \assert($resolver instanceof ReservationCzkPreviewResolver);

        return $resolver->batch($reservations, new \DateTimeImmutable(self::TODAY));
    }

    private function useCnb(CnbExchangeRateClient $cnb): void
    {
        $this->container->set(CnbExchangeRateClient::class, $cnb);
    }

    /** Uložený kurz stačí — na ČNB se sahat nemá. */
    private function expectNoCnbCall(): void
    {
        $cnb = $this->createMock(CnbExchangeRateClient::class);
        $cnb->expects(self::never())->method('getRate');
        $this->useCnb($cnb);
    }

    private function useDailyRate(float $rate): void
    {
        $cnb = $this->createStub(CnbExchangeRateClient::class);
        $cnb->method('getRate')->willReturn(new CnbRate('EUR', $rate, new \DateTimeImmutable('2026-08-22')));
        $this->useCnb($cnb);
    }

    private function makeReservation(?string $price, string $currency): Reservation
    {
        static $day = 0;
        $r = new Reservation(Channel::BOOKING, new \DateTimeImmutable(sprintf('2026-04-%02d', ++$day)));
        $r->setCheckOut($r->getCheckIn()->modify('+2 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Test ' . $day);
        $r->setPriceTotal($price)->setPriceCurrency($currency);
        $this->em->persist($r);

        return $r;
    }

    private function makeInvoice(Reservation $r): Invoice
    {
        static $seq = 0;
        $n = ++$seq + 800;
        $invoice = new Invoice(
            sprintf('2026%03d', $n),
            2026,
            $n,
            InvoiceType::FULL,
            $r,
            new \DateTimeImmutable('2026-04-20'),
            new \DateTimeImmutable('2026-05-04'),
        );
        $invoice->setTotalAmount('2510.00');
        $this->em->persist($invoice);

        return $invoice;
    }
}
