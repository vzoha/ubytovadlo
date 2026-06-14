<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Timeline;

use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Entity\ReservationNote;
use App\Enum\ActionType;
use App\Enum\Channel;
use App\Enum\NoteType;
use App\Timeline\ReservationTimelineBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReservationTimelineBuilderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReservationTimelineBuilder $builder;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->builder = $container->get(ReservationTimelineBuilder::class);

        $this->em->createQuery('DELETE FROM ' . ReservationAction::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . ReservationNote::class . ' n')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
    }

    public function testMergesAndSortsChronologically(): void
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('+5 days'));
        $r->setGuestName('Test');
        $this->em->persist($r);

        $note = new ReservationNote($r, NoteType::HOVOR, 'Volal kvůli psovi');
        $note->setOccurredAt(new \DateTimeImmutable('-1 day'));
        $this->em->persist($note);

        $action = new ReservationAction($r, ActionType::CUSTOM_REMINDER, new \DateTimeImmutable('+3 days'));
        $action->setPayload(['text' => 'Připravit postýlku']);
        $this->em->persist($action);
        $this->em->flush();

        $items = $this->builder->build($r);

        // založení (event) + poznámka (note) + akce (action)
        self::assertCount(3, $items);
        $kinds = array_map(static fn ($i) => $i->kind, $items);
        self::assertContains('event', $kinds);
        self::assertContains('note', $kinds);
        self::assertContains('action', $kinds);

        // seřazeno vzestupně podle data
        for ($i = 1; $i < count($items); $i++) {
            self::assertLessThanOrEqual($items[$i]->at, $items[$i - 1]->at);
        }

        $actionItem = array_values(array_filter($items, static fn ($i) => $i->kind === 'action'))[0];
        self::assertTrue($actionItem->isOpenAction());
        self::assertSame('Připravit postýlku', $actionItem->body);
    }
}
