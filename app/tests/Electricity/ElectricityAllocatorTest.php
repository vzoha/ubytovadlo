<?php

declare(strict_types=1);

namespace App\Tests\Electricity;

use App\Entity\AirbnbStatement;
use App\Entity\BookingMonthlyInvoice;
use App\Entity\ElectricityReading;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Reservation;
use App\Entity\VatPeriod;
use App\Enum\Channel;
use App\Enum\ElectricitySource;
use App\Enum\ReservationStatus;
use App\Service\Electricity\ElectricityAllocator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ElectricityAllocatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ElectricityAllocator $allocator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->allocator = $container->get(ElectricityAllocator::class);

        $this->em->createQuery('DELETE FROM ' . InvoiceLine::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . AirbnbStatement::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . BookingMonthlyInvoice::class . ' b')->execute();
        $this->em->createQuery('DELETE FROM ' . VatPeriod::class . ' v')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . ElectricityReading::class . ' e')->execute();
    }

    public function testSingleReservationGetsFullConsumption(): void
    {
        $r = $this->makeReservation('2026-01-10', '2026-01-13');
        $this->makeReading('2026-01-09', 5000, 3000);
        $this->makeReading('2026-01-14', 5120, 3060);
        $this->em->flush();

        $stats = $this->allocator->rebalanceAll();

        self::assertSame(1, $stats->intervals);
        self::assertSame(1, $stats->reservations);
        $this->em->refresh($r);
        self::assertSame(120, $r->getVtKwh());
        self::assertSame(60, $r->getNtKwh());
        self::assertSame(ElectricitySource::ALLOCATED, $r->getElectricitySource());
    }

    public function testWinterStayGetsMoreThanSummerStay(): void
    {
        $winter = $this->makeReservation('2026-01-10', '2026-01-12'); // 2 noci v lednu
        $summer = $this->makeReservation('2026-07-10', '2026-07-12'); // 2 noci v červenci
        $this->makeReading('2026-01-09', 1000, 500);
        $this->makeReading('2026-07-20', 1300, 650);
        $this->em->flush();

        $this->allocator->rebalanceAll();
        $this->em->refresh($winter);
        $this->em->refresh($summer);

        // Stejně dlouhé pobyty, ale zimní má mnohem vyšší faktor → dostane víc.
        self::assertGreaterThan($summer->getVtKwh(), $winter->getVtKwh());
        self::assertGreaterThan($summer->getNtKwh(), $winter->getNtKwh());
        // Součet musí sedět na celou spotřebu intervalu (zaokrouhlovací zbytek řešen).
        self::assertSame(300, $winter->getVtKwh() + $summer->getVtKwh());
        self::assertSame(150, $winter->getNtKwh() + $summer->getNtKwh());
    }

    public function testMeasuredReservationIsSkipped(): void
    {
        $a = $this->makeReservation('2026-03-01', '2026-03-03');
        $a->setVtKwh(99)->setNtKwh(11)->setElectricitySource(ElectricitySource::MEASURED);
        $b = $this->makeReservation('2026-03-10', '2026-03-12');
        $this->makeReading('2026-02-28', 0, 0);
        $this->makeReading('2026-03-15', 200, 100);
        $this->em->flush();

        $stats = $this->allocator->rebalanceAll();

        $this->em->refresh($a);
        $this->em->refresh($b);
        self::assertSame(99, $a->getVtKwh(), 'measured rezervace zůstala beze změny');
        self::assertSame(ElectricitySource::MEASURED, $a->getElectricitySource());
        self::assertSame(200, $b->getVtKwh(), 'b dostala celou spotřebu intervalu');
        self::assertSame(100, $b->getNtKwh());
        self::assertSame(1, $stats->skippedMeasured);
    }

    public function testLargeGroupGetsMoreThanCouple(): void
    {
        // Dva pobyty stejné délky ve stejném měsíci, jen rozdílný počet hostů.
        $couple = $this->makeReservation('2026-01-05', '2026-01-07');
        $couple->setGuestsAdult(2);
        $family = $this->makeReservation('2026-01-20', '2026-01-22');
        $family->setGuestsAdult(2)->setGuestsChild(2);
        $this->makeReading('2026-01-04', 0, 0);
        $this->makeReading('2026-01-25', 220, 110);
        $this->em->flush();

        $this->allocator->rebalanceAll();
        $this->em->refresh($couple);
        $this->em->refresh($family);

        // 1.20× větší skupina → o 20 % víc kWh. Při ratio 1:1.2 by měli dostat
        // 100/220 = 45 % vs 120/220 = 55 % (zhruba).
        self::assertGreaterThan($couple->getTotalKwh(), $family->getTotalKwh());
        $ratio = $family->getTotalKwh() / $couple->getTotalKwh();
        self::assertEqualsWithDelta(1.20, $ratio, 0.05);
    }

    public function testEmptyIntervalNoCrash(): void
    {
        $this->makeReading('2026-06-01', 0, 0);
        $this->makeReading('2026-06-10', 50, 25);
        $this->em->flush();

        $stats = $this->allocator->rebalanceAll();
        self::assertSame(0, $stats->reservations);
    }

    public function testRebalanceAroundTouchesOnlyAdjacentIntervals(): void
    {
        // 3 intervaly: A-B, B-C, C-D. Přidáním odečtu mezi B a C se mají přepočítat
        // jen dva nové intervaly (B→nový, nový→C), ne celá historie.
        $this->makeReading('2026-01-01', 0, 0);
        $this->makeReading('2026-02-01', 100, 50);
        $this->makeReading('2026-04-01', 300, 150);
        $this->makeReading('2026-05-01', 400, 200);
        $this->em->flush();
        $this->allocator->rebalanceAll();

        $new = $this->makeReading('2026-03-01', 200, 100);
        $this->em->flush();

        $stats = $this->allocator->rebalanceAround($new->getReadAt());
        self::assertSame(2, $stats->intervals);
    }

    public function testRebalanceAroundAfterDeleteMergesNeighbors(): void
    {
        $this->makeReading('2026-01-01', 0, 0);
        $middle = $this->makeReading('2026-02-01', 100, 50);
        $this->makeReading('2026-03-01', 200, 100);
        $this->em->flush();
        $this->allocator->rebalanceAll();

        $date = $middle->getReadAt();
        $this->em->remove($middle);
        $this->em->flush();

        $stats = $this->allocator->rebalanceAround($date);
        self::assertSame(1, $stats->intervals);
    }

    public function testNegativeConsumptionThrows(): void
    {
        $this->makeReading('2026-06-01', 1000, 500);
        $this->makeReading('2026-06-10', 900, 400);
        $this->em->flush();

        $this->expectException(\LogicException::class);
        $this->allocator->rebalanceAll();
    }

    private function makeReservation(string $checkIn, string $checkOut): Reservation
    {
        $r = new Reservation(Channel::BOOKING, new \DateTimeImmutable($checkIn));
        $r->setCheckOut(new \DateTimeImmutable($checkOut));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Test ' . $checkIn);
        $this->em->persist($r);

        return $r;
    }

    private function makeReading(string $date, int $vt, int $nt): ElectricityReading
    {
        $reading = new ElectricityReading(new \DateTimeImmutable($date), $vt, $nt);
        $this->em->persist($reading);

        return $reading;
    }
}
