<?php

declare(strict_types=1);

namespace App\Tests\Cleaning;

use App\Entity\AirbnbStatement;
use App\Entity\BookingMonthlyInvoice;
use App\Entity\Cleaning;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Reservation;
use App\Entity\Setting;
use App\Entity\VatPeriod;
use App\Enum\Channel;
use App\Enum\CleaningType;
use App\Repository\CleaningRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CleaningProvisionerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CleaningRepository $cleanings;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->cleanings = $container->get(CleaningRepository::class);

        $this->em->createQuery('DELETE FROM ' . InvoiceLine::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . AirbnbStatement::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . BookingMonthlyInvoice::class . ' b')->execute();
        $this->em->createQuery('DELETE FROM ' . VatPeriod::class . ' v')->execute();
        $this->em->createQuery('DELETE FROM ' . Cleaning::class . ' c')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s')->execute();
        $this->em->flush();
    }

    public function testCreatingReservationAutoCreatesCleaning(): void
    {
        $r = new Reservation(Channel::BOOKING, new \DateTimeImmutable('2026-04-01'));
        $r->setCheckOut(new \DateTimeImmutable('2026-04-03'));
        $r->setGuestName('Test');
        $r->setGuestsAdult(2);
        $this->em->persist($r);
        $this->em->flush();

        $cleaning = $this->cleanings->findForReservation($r);
        self::assertNotNull($cleaning, 'po persist rezervace má vzniknout cleaning');
        self::assertSame(CleaningType::OWNER, $cleaning->getType());
        self::assertSame(400, $cleaning->getCostCzk());
        self::assertSame(0, $cleaning->getPayoutCzk(), 'Barča se nevyplácí');
    }

    public function testLargeGroupGetsLargerPrice(): void
    {
        $r = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('2026-05-01'));
        $r->setGuestsAdult(2);
        $r->setGuestsChild(2);
        $this->em->persist($r);
        $this->em->flush();

        $cleaning = $this->cleanings->findForReservation($r);
        self::assertSame(600, $cleaning->getCostCzk(), '4 hosté > práh 2 → větší cena');
    }

    public function testProvisionerIsIdempotent(): void
    {
        $r = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('2026-06-01'));
        $r->setGuestsAdult(2);
        $this->em->persist($r);
        $this->em->flush();

        $first = $this->cleanings->findForReservation($r);
        $first->setNote('ručně upraveno');
        $first->setCostCzk(999);
        $this->em->flush();

        // Druhý persist by neměl spustit nový cleaning ani přepsat ten existující.
        $this->em->flush();
        $this->em->refresh($first);
        self::assertSame(999, $first->getCostCzk());
        self::assertSame('ručně upraveno', $first->getNote());
    }
}
