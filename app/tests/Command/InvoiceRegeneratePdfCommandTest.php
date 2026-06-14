<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Enum\PdfSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class InvoiceRegeneratePdfCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . InvoiceLine::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();

        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('app:invoice:regenerate-pdf'));
    }

    public function testExternalPdfIsSkippedInBulk(): void
    {
        $external = $this->externalInvoice();
        $this->em->flush();
        $originalPath = $external->getPdfPath();

        $this->tester->execute([]);

        $this->em->refresh($external);
        self::assertSame($originalPath, $external->getPdfPath(), 'Externí PDF se nesmí přepsat.');
        self::assertSame(PdfSource::EXTERNAL, $external->getPdfSource());
        self::assertStringContainsString('přeskočeno', $this->tester->getDisplay());
    }

    private function externalInvoice(): Invoice
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2025-05-10'));
        $r->setGuestName('Legacy Host');
        $this->em->persist($r);

        $invoice = new Invoice('2025030', 2025, InvoiceType::DEPOSIT, $r, new \DateTimeImmutable('2025-05-10'), new \DateTimeImmutable('2025-05-20'));
        $invoice->setTotalAmount('1000.00');
        $invoice->setPdfPath('var/invoices/2025/2025030.pdf');
        $invoice->setPdfSource(PdfSource::EXTERNAL);
        $this->em->persist($invoice);

        return $invoice;
    }
}
