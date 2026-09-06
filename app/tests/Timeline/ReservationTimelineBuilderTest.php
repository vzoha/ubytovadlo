<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Timeline;

use App\Entity\Embeddable\GuestContact;
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Entity\ReservationNote;
use App\Enum\ActionType;
use App\Enum\Channel;
use App\Enum\NoteType;
use App\Timeline\ReservationTimelineBuilder;
use App\Timeline\TimelineItem;
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

    public function testClosedActionSitsAtTimeItHappened(): void
    {
        $r = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('+5 days'));
        $r->setGuestName('Test');
        $this->em->persist($r);

        // Zpráva plánovaná před týdnem, ručně potvrzená až teď (chat portálu).
        $action = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('-7 days'));
        $action->markDone('Vyřízeno ručně.');
        $this->em->persist($action);
        $this->em->flush();

        $items = $this->builder->build($r);
        $actionItem = array_values(array_filter($items, static fn ($i) => $i->kind === 'action'))[0];

        self::assertEqualsWithDelta(
            $action->getExecutedAt()?->getTimestamp(),
            $actionItem->at->getTimestamp(),
            1,
        );
        self::assertStringContainsString('plánováno', (string) $actionItem->meta);
    }

    public function testMessageToOtaGuestWithoutEmailGoesByChat(): void
    {
        $r = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('+5 days'));
        $r->setGuestName('Test');
        $this->em->persist($r);

        $message = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('+3 days'));
        $reminder = new ReservationAction($r, ActionType::CUSTOM_REMINDER, new \DateTimeImmutable('+4 days'));
        $this->em->persist($message);
        $this->em->persist($reminder);
        $this->em->flush();

        $items = $this->actionsByType($this->builder->build($r));

        self::assertTrue($items[ActionType::PRE_ARRIVAL_MESSAGE->value]->byChat);
        self::assertSame('💬', $items[ActionType::PRE_ARRIVAL_MESSAGE->value]->icon);
        // Připomínka není zpráva hostovi — ikonu ani chování nemění.
        self::assertFalse($items[ActionType::CUSTOM_REMINDER->value]->byChat);
        self::assertSame(ActionType::CUSTOM_REMINDER->icon(), $items[ActionType::CUSTOM_REMINDER->value]->icon);
    }

    public function testMessageToGuestWithEmailStaysMail(): void
    {
        $r = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('+5 days'));
        $r->setGuestName('Test');
        $r->setGuestContact(new GuestContact('host@example.com'));
        $this->em->persist($r);
        $this->em->persist(new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('+3 days')));
        $this->em->flush();

        $items = $this->actionsByType($this->builder->build($r));
        $item = $items[ActionType::PRE_ARRIVAL_MESSAGE->value];

        self::assertFalse($item->byChat);
        self::assertSame(ActionType::PRE_ARRIVAL_MESSAGE->icon(), $item->icon);
    }

    public function testOpenActionSitsAtItsDeadlineWithoutPlanNote(): void
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('+5 days'));
        $r->setGuestName('Test');
        $this->em->persist($r);

        $when = new \DateTimeImmutable('+3 days');
        $action = new ReservationAction($r, ActionType::CUSTOM_REMINDER, $when);
        $this->em->persist($action);
        $this->em->flush();

        $items = $this->builder->build($r);
        $actionItem = array_values(array_filter($items, static fn ($i) => $i->kind === 'action'))[0];

        self::assertSame($when->getTimestamp(), $actionItem->at->getTimestamp());
        self::assertStringNotContainsString('plánováno', (string) $actionItem->meta);
    }

    /**
     * @param TimelineItem[] $items
     *
     * @return array<string, TimelineItem>
     */
    private function actionsByType(array $items): array
    {
        $byType = [];
        foreach ($items as $item) {
            if ($item->action !== null) {
                $byType[$item->action->getType()->value] = $item;
            }
        }

        return $byType;
    }
}
