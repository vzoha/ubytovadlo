<?php

declare(strict_types=1);

namespace App\Tests\Reservation;

use App\Entity\AirbnbStatement;
use App\Entity\BookingMonthlyInvoice;
use App\Entity\Cleaning;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Reservation;
use App\Entity\Setting;
use App\Entity\VatPeriod;
use App\Enum\Channel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ReservationCheckinTokenListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

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

    public function testTokenIsGeneratedOnPersist(): void
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-06-01'));
        $r->setCheckOut(new \DateTimeImmutable('2026-06-03'));
        self::assertNull($r->getCheckinToken(), 'pred persist token neni nastaveny');

        $this->em->persist($r);
        $this->em->flush();

        $token = $r->getCheckinToken();
        self::assertNotNull($token);
        self::assertSame(64, strlen($token), '256 bit entropie = 64 hex znaku');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testTokenIsUniquePerReservation(): void
    {
        $a = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-06-01'));
        $a->setCheckOut(new \DateTimeImmutable('2026-06-03'));
        $b = new Reservation(Channel::BOOKING, new \DateTimeImmutable('2026-07-01'));
        $b->setCheckOut(new \DateTimeImmutable('2026-07-03'));

        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        self::assertNotSame($a->getCheckinToken(), $b->getCheckinToken());
    }

    public function testExistingTokenIsPreserved(): void
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-06-01'));
        $r->setCheckOut(new \DateTimeImmutable('2026-06-03'));
        $r->setCheckinToken('predem-nastaveny-token-z-importu');

        $this->em->persist($r);
        $this->em->flush();

        self::assertSame('predem-nastaveny-token-z-importu', $r->getCheckinToken());
    }
}
