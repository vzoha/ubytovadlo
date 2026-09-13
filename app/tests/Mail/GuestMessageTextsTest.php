<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Entity\QuickMessage;
use App\Entity\Reservation;
use App\Entity\Setting;
use App\Enum\Channel;
use App\Mail\GuestMessageTexts;
use App\Mail\QuickMessageSignature;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Rychlá zpráva odchází do chatu jako hotový text — s dosazenými údaji
 * rezervace a s podpisem z nastavení na konci.
 */
final class GuestMessageTextsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GuestMessageTexts $texts;
    private QuickMessageSignature $signature;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->texts = $container->get(GuestMessageTexts::class);
        $this->signature = $container->get(QuickMessageSignature::class);

        $this->em->createQuery('DELETE FROM ' . QuickMessage::class . ' q')->execute();
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s WHERE s.key = :key')
            ->setParameter('key', QuickMessageSignature::KEY)
            ->execute();
        $this->em->flush();

        $message = new QuickMessage('Uvítání', 'Dobrý den, {{ guest_first_name }}.');
        $message->setSortOrder(0);
        $this->em->persist($message);
        $this->em->flush();
    }

    public function testSignatureIsAppendedToEveryMessage(): void
    {
        $this->signature->save("Anna\n+420 111 222 333");

        $texts = $this->texts->forReservation($this->reservation());

        self::assertSame("Dobrý den, Jana.\n\nAnna\n+420 111 222 333", $texts[0]['text']);
    }

    public function testEmptySignatureLeavesTheTextAlone(): void
    {
        $this->signature->save('');

        $texts = $this->texts->forReservation($this->reservation());

        self::assertSame('Dobrý den, Jana.', $texts[0]['text']);
    }

    private function reservation(): Reservation
    {
        $reservation = new Reservation(Channel::DIRECT, new \DateTimeImmutable('+10 days'));
        $reservation->setCheckOut(new \DateTimeImmutable('+13 days'));
        $reservation->setGuestName('Jana Nováková');

        return $reservation;
    }
}
