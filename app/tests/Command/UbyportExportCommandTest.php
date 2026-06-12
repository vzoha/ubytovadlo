<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\AccommodationProfile;
use App\Entity\GuestDocument;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Repository\GuestDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class UbyportExportCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $tester;
    private string $outputDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . GuestDocument::class . ' g')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . AccommodationProfile::class . ' p')->execute();
        $this->em->flush();

        $this->persistProfile();

        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('app:ubyport:export'));

        $this->outputDir = sys_get_temp_dir() . '/ubyport_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDir)) {
            foreach (glob($this->outputDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->outputDir);
        }
        parent::tearDown();
    }

    private function persistProfile(): void
    {
        $p = new AccommodationProfile();
        $p->setIdub('123456789012');
        $p->setKod('VODPO');
        $p->setNazev('Hotel Pošta');
        $p->setSpojeni('Jan Sibelius');
        $p->setOkres('Strakonice');
        $p->setObec('Vodňany');
        $p->setUlice('Alešova');
        $p->setCp('26');
        $p->setPsc('38901');
        $this->em->persist($p);
        $this->em->flush();
    }

    private function persistForeigner(): GuestDocument
    {
        $r = new Reservation(Channel::BOOKING, new \DateTimeImmutable('2026-06-15'));
        $r->setCheckOut(new \DateTimeImmutable('2026-06-18'));
        $this->em->persist($r);

        $doc = new GuestDocument($r, 'Hans', 'Müller', new \DateTimeImmutable('1980-04-16'));
        $doc->setNationalityCode('DEU');
        $doc->setDocumentNumber('AB1234567');
        $doc->confirm();
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    public function testRollingModeMarksReported(): void
    {
        $doc = $this->persistForeigner();
        $docId = $doc->getId();

        $exit = $this->tester->execute(['--output-dir' => $this->outputDir]);

        self::assertSame(0, $exit);
        self::assertCount(1, glob($this->outputDir . '/*.unl') ?: []);

        $this->em->clear();
        $reloaded = static::getContainer()->get(GuestDocumentRepository::class)->find($docId);
        self::assertNotNull($reloaded->getUbyportReportedAt(), 'rolling režim musí označit jako nahlášené');
    }

    public function testPeriodReExportDoesNotMark(): void
    {
        $doc = $this->persistForeigner();
        $docId = $doc->getId();

        $exit = $this->tester->execute([
            '--from' => '2026-06-01',
            '--to' => '2026-06-30',
            '--output-dir' => $this->outputDir,
        ]);

        self::assertSame(0, $exit);
        self::assertCount(1, glob($this->outputDir . '/*.unl') ?: []);

        $this->em->clear();
        $reloaded = static::getContainer()->get(GuestDocumentRepository::class)->find($docId);
        self::assertNull($reloaded->getUbyportReportedAt(), 're-export za období nesmí měnit značku nahlášení');
    }

    public function testRollingModeSkipsAlreadyReported(): void
    {
        $doc = $this->persistForeigner();
        $doc->markUbyportReported(new \DateTimeImmutable('2026-06-16'));
        $this->em->flush();

        $exit = $this->tester->execute(['--output-dir' => $this->outputDir]);

        self::assertSame(0, $exit);
        self::assertSame([], glob($this->outputDir . '/*.unl') ?: [], 'už nahlášený se nesmí znovu exportovat');
        self::assertStringContainsString('Nikdo k nahlášení', $this->tester->getDisplay());
    }

    public function testPeriodRequiresBothBounds(): void
    {
        $this->persistForeigner();

        $exit = $this->tester->execute([
            '--from' => '2026-06-01',
            '--output-dir' => $this->outputDir,
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--from i --to', $this->tester->getDisplay());
    }
}
