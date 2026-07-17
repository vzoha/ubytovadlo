<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Embeddable\GuestContact;
use App\Entity\GuestMessage;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\MessageTemplate;
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Enum\MessageKind;
use App\Enum\SendMode;
use App\Mail\MailSettingsProvider;
use App\Mail\MessageTemplateDefaults;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ActionsRunCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . GuestMessage::class . ' g')->execute();
        $this->em->createQuery('DELETE FROM ' . MessageTemplate::class . ' t')->execute();
        $this->em->createQuery('DELETE FROM ' . ReservationAction::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . InvoiceLine::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();

        // Odchozí adresa odesílatele — bez ní nemá zpráva hostovi platné From.
        $container->get(SettingRepository::class)->set(MailSettingsProvider::SENDER_EMAIL, 'odesilatel@example.cz');
        $this->em->flush();

        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('app:actions:run'));
    }

    public function testIssueFinalInvoiceActionResolvesWhenInvoiceExists(): void
    {
        $r = $this->reservation();
        $invoice = new Invoice('2026777', 2026, 777, InvoiceType::FINAL, $r, new \DateTimeImmutable(), new \DateTimeImmutable('+14 days'));
        $invoice->setTotalAmount('3000.00');
        $this->em->persist($invoice);

        $due = new ReservationAction($r, ActionType::ISSUE_FINAL_INVOICE, new \DateTimeImmutable('-1 hour'));
        $this->em->persist($due);
        $this->em->flush();

        $this->tester->execute([]);

        $this->em->refresh($due);
        self::assertSame(ActionStatus::DONE, $due->getStatus());
        self::assertNotNull($due->getExecutedAt());
    }

    public function testGuestMessageInWindowWithEmailIsSent(): void
    {
        // Příjezd v budoucnu → pre-arrival je v okně; host má e-mail → odešle se
        // (null transport v testu „odeslání" potvrdí) a akce se označí DONE.
        // Šablony jsou defaultně vypnuté, tahle musí být zapnutá, aby se odeslala.
        $this->enableTemplate(MessageKind::PRE_ARRIVAL);

        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('+5 days'));
        $r->setCheckOut(new \DateTimeImmutable('+7 days'));
        $r->setGuestName('Future Host');
        $r->setGuestContact(new GuestContact('future@example.com'));
        $this->em->persist($r);
        $msg = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('-1 hour'));
        $this->em->persist($msg);
        $this->em->flush();

        $this->tester->execute([]);

        $this->em->refresh($msg);
        self::assertSame(ActionStatus::DONE, $msg->getStatus());

        $sent = $this->em->getRepository(GuestMessage::class)->findOneBy(['reservation' => $r]);
        self::assertNotNull($sent);
        self::assertSame('future@example.com', $sent->getToEmail());
    }

    public function testGuestMessageInWindowWithoutEmailSkipped(): void
    {
        // Stejné okno a zapnuté automatické odesílání, ale host nemá e-mail →
        // zprávu nelze poslat → SKIPPED.
        $this->enableTemplate(MessageKind::PRE_ARRIVAL);

        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('+5 days'));
        $r->setCheckOut(new \DateTimeImmutable('+7 days'));
        $r->setGuestName('No Email Host');
        $this->em->persist($r);
        $msg = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('-1 hour'));
        $this->em->persist($msg);
        $this->em->flush();

        $this->tester->execute([]);

        $this->em->refresh($msg);
        self::assertSame(ActionStatus::SKIPPED, $msg->getStatus());
    }

    public function testDraftGuestMessageWaitsInsteadOfSending(): void
    {
        // Režim „jen návrh": zpráva je v okně a host má e-mail, přesto ji cron
        // sám neodešle — zůstane naplánovaná k ručnímu odeslání.
        $this->setTemplateMode(MessageKind::PRE_ARRIVAL, SendMode::DRAFT);

        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('+5 days'));
        $r->setCheckOut(new \DateTimeImmutable('+7 days'));
        $r->setGuestName('Draft Host');
        $r->setGuestContact(new GuestContact('draft@example.com'));
        $this->em->persist($r);
        $msg = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('-1 hour'));
        $this->em->persist($msg);
        $this->em->flush();

        $this->tester->execute([]);

        $this->em->refresh($msg);
        self::assertSame(ActionStatus::PLANNED, $msg->getStatus());
        self::assertNull($this->em->getRepository(GuestMessage::class)->findOneBy(['reservation' => $r]));
    }

    public function testDisabledGuestMessageIsSkipped(): void
    {
        // Vypnutá zpráva se v okně přeskočí (neodešle, ani nečeká).
        $this->setTemplateMode(MessageKind::PRE_ARRIVAL, SendMode::OFF);

        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('+5 days'));
        $r->setCheckOut(new \DateTimeImmutable('+7 days'));
        $r->setGuestName('Off Host');
        $r->setGuestContact(new GuestContact('off@example.com'));
        $this->em->persist($r);
        $msg = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('-1 hour'));
        $this->em->persist($msg);
        $this->em->flush();

        $this->tester->execute([]);

        $this->em->refresh($msg);
        self::assertSame(ActionStatus::SKIPPED, $msg->getStatus());
    }

    public function testStaleGuestMessageSkipped(): void
    {
        // Host už přijel → pre-arrival zpráva je po okně → SKIPPED (neposlat zpětně).
        $r = $this->reservation();
        $msg = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('-3 days'));
        $this->em->persist($msg);
        $this->em->flush();

        $this->tester->execute([]);

        $this->em->refresh($msg);
        self::assertSame(ActionStatus::SKIPPED, $msg->getStatus());
        self::assertNotNull($msg->getExecutedAt());
    }

    private function enableTemplate(MessageKind $kind): void
    {
        $this->setTemplateMode($kind, SendMode::AUTO);
    }

    private function setTemplateMode(MessageKind $kind, SendMode $mode): void
    {
        $template = MessageTemplateDefaults::for($kind);
        $template->setMode($mode);
        $this->em->persist($template);
        $this->em->flush();
    }

    private function reservation(): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('-2 days'));
        $r->setCheckOut(new \DateTimeImmutable('today'));
        $r->setGuestName('Test Host');
        $this->em->persist($r);

        return $r;
    }
}
