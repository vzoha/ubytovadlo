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
use App\Enum\CleaningType;
use App\Repository\SettingRepository;
use App\Service\Cleaning\CleaningPriceList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CleaningPriceListTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CleaningPriceList $priceList;
    private SettingRepository $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->priceList = $container->get(CleaningPriceList::class);
        $this->settings = $container->get(SettingRepository::class);

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

    public function testBarcaSmallStayUsesDefaultPrice(): void
    {
        self::assertSame(400, $this->priceList->costFor(CleaningType::OWNER, 2));
    }

    public function testBarcaLargeStayUsesDefaultPrice(): void
    {
        self::assertSame(600, $this->priceList->costFor(CleaningType::OWNER, 4));
    }

    public function testBarcaThresholdRespectsSettingOverride(): void
    {
        $this->settings->set('cleaning.owner.threshold_guests', '3');
        $this->settings->set('cleaning.owner.price_small', '500');
        $this->settings->set('cleaning.owner.price_large', '800');
        $this->em->flush();

        self::assertSame(500, $this->priceList->costFor(CleaningType::OWNER, 3));
        self::assertSame(800, $this->priceList->costFor(CleaningType::OWNER, 4));
    }

    public function testNikolaUsesFlatPrice(): void
    {
        self::assertSame(700, $this->priceList->costFor(CleaningType::CLEANER, 2));
        self::assertSame(700, $this->priceList->costFor(CleaningType::CLEANER, 5));
    }

    public function testNikolaSPranimUsesFlatPrice(): void
    {
        self::assertSame(1000, $this->priceList->costFor(CleaningType::CLEANER_LAUNDRY, 4));
    }

    public function testBarcaPayoutIsZero(): void
    {
        self::assertSame(0, $this->priceList->payoutFor(CleaningType::OWNER, 400));
    }

    public function testExternalPayoutEqualsCost(): void
    {
        self::assertSame(700, $this->priceList->payoutFor(CleaningType::CLEANER, 700));
        self::assertSame(1000, $this->priceList->payoutFor(CleaningType::CLEANER_LAUNDRY, 1000));
    }
}
