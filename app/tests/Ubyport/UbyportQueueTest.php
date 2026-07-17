<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Ubyport;

use App\Entity\GuestDocument;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Ubyport\UbyportQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UbyportQueueTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->clearReservations();
    }

    public function testListsReservationsWithForeigners(): void
    {
        $this->persistForeigner('2026-06-10', 'Müller');
        $this->persistForeigner('2026-06-20', 'Schmidt');

        $rows = $this->queue()->rows(new \DateTimeImmutable('2026-06-15'));

        self::assertCount(2, $rows);
        self::assertSame('Müller', $rows[0]->foreigners[0]->getLastName());
        self::assertSame('Schmidt', $rows[1]->foreigners[0]->getLastName());
    }

    /**
     * Doklady se čtou dávkou, ne dotazem na každou rezervaci — jinak počet
     * dotazů roste s frontou a Ubyport i hlavní přehled se plazí.
     */
    public function testDoesNotQueryDocumentsPerReservation(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->persistForeigner(sprintf('2026-06-%02d', $i + 9), 'Host' . $i);
        }
        $this->em->clear();

        $before = $this->queryCount();
        $rows = $this->queue()->rows(new \DateTimeImmutable('2026-06-15'));
        $used = $this->queryCount() - $before - 1; // -1 za měřicí dotaz samotný

        self::assertCount(6, $rows);
        self::assertLessThanOrEqual(3, $used, sprintf('fronta spotřebovala %d dotazů na 6 rezervací', $used));
    }

    /**
     * Fronta počítá se VŠEMI rezervacemi v DB, takže test potřebuje čistý stůl —
     * včetně toho, co na rezervacích visí a co tu nechaly předchozí testy.
     */
    private function clearReservations(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['guest_document', 'invoice_line', 'invoice', 'reservation_receipt', 'ledger_entry', 'cleaning', 'reservation_action', 'reservation_note', 'reservation'] as $table) {
            $conn->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->em->clear();
    }

    private function queryCount(): int
    {
        /** @var array{Variable_name: string, Value: string}|false $row */
        $row = $this->em->getConnection()->fetchAssociative("SHOW SESSION STATUS LIKE 'Questions'");

        return $row === false ? 0 : (int) $row['Value'];
    }

    private function queue(): UbyportQueue
    {
        $queue = static::getContainer()->get(UbyportQueue::class);
        \assert($queue instanceof UbyportQueue);

        return $queue;
    }

    private function persistForeigner(string $checkIn, string $lastName): void
    {
        $r = new Reservation(Channel::BOOKING, new \DateTimeImmutable($checkIn));
        $r->setCheckOut((new \DateTimeImmutable($checkIn))->modify('+3 days'));
        $this->em->persist($r);

        $doc = new GuestDocument($r, 'Hans', $lastName, new \DateTimeImmutable('1980-04-16'));
        $doc->setNationalityCode('DEU');
        $doc->setDocumentNumber('AB1234567');
        $doc->confirm();
        $this->em->persist($doc);
        $this->em->flush();
    }
}
