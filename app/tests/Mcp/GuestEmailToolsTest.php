<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Email\GuestInbox\MailAddress;
use App\Entity\GuestEmail;
use App\Enum\EmailDirection;
use App\GuestMail\GuestEmailReplier;
use App\Mail\GuestEmailFactory;
use App\Mail\MailSettingsProvider;
use App\Mcp\GuestEmailArgument;
use App\Mcp\McpToolException;
use App\Mcp\Tool\ListGuestEmailsTool;
use App\Mcp\Tool\MarkGuestEmailHandledTool;
use App\Mcp\Tool\ReadGuestEmailTool;
use App\Mcp\Tool\ReplyToGuestEmailTool;
use App\Repository\SettingRepository;
use App\Tests\Mail\CapturingMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mime\Email;

final class GuestEmailToolsTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $em->getConnection()->executeStatement('DELETE FROM guest_email');
    }

    public function testListReturnsRecentUnansweredInbound(): void
    {
        $this->email('1', EmailDirection::INBOUND, 'Volno v srpnu?', 'now -1 day');
        $this->email('2', EmailDirection::INBOUND, 'Zodpovězený', 'now -1 day', answered: true);
        $this->email('3', EmailDirection::OUTBOUND, 'Re: Volno', 'now -1 day');
        $this->email('4', EmailDirection::INBOUND, 'Starý', 'now -30 days');

        $result = $this->listTool()->call([]);

        self::assertSame(1, $result['count']);
        self::assertSame('Volno v srpnu?', $result['emails'][0]['subject']);
        self::assertFalse($result['emails'][0]['answered']);
    }

    public function testListIncludesAnsweredAndOlderOnRequest(): void
    {
        $this->email('2', EmailDirection::INBOUND, 'Zodpovězený', 'now -1 day', answered: true);
        $this->email('4', EmailDirection::INBOUND, 'Starý', 'now -30 days');

        $result = $this->listTool()->call(['includeAnswered' => true, 'days' => 60]);

        self::assertSame(2, $result['count']);
    }

    public function testReadReturnsTextAndConversationWithSameGuest(): void
    {
        $question = $this->email('1', EmailDirection::INBOUND, 'Volno v srpnu?', 'now -2 days', 'Máte volno 12.–19. 8.?');
        $this->email('3', EmailDirection::OUTBOUND, 'Re: Volno', 'now -1 day');
        $this->email('5', EmailDirection::INBOUND, 'Cizí', 'now -1 day', from: 'petr@example.com');

        $result = $this->readTool()->call(['id' => $question->getId()]);

        self::assertSame('Máte volno 12.–19. 8.?', $result['text']);
        self::assertCount(1, $result['conversation']);
        self::assertSame('hostovi', $result['conversation'][0]['direction']);
    }

    public function testReadUnknownIdIsToolError(): void
    {
        $this->expectException(McpToolException::class);
        $this->readTool()->call(['id' => 999999]);
    }

    public function testReplySendsApprovedTextAndAnswersEmail(): void
    {
        $question = $this->email('1', EmailDirection::INBOUND, 'Volno v srpnu?', 'now -1 day');
        $mailer = new CapturingMailer();

        $result = $this->replyTool($mailer)->call(['id' => $question->getId(), 'text' => 'Dobrý den, máme volno.']);

        self::assertTrue($result['sent']);
        self::assertSame('jana@example.com', $result['to']);
        self::assertSame('Re: Volno v srpnu?', $result['subject']);
        self::assertInstanceOf(Email::class, $mailer->sent);
        self::assertSame('Dobrý den, máme volno.', $mailer->sent->getTextBody());
        self::assertTrue($question->isAnswered());
    }

    public function testReplyRefusesUnfilledPlaceholder(): void
    {
        $question = $this->email('1', EmailDirection::INBOUND, 'Volno?', 'now -1 day');
        $mailer = new CapturingMailer();

        try {
            $this->replyTool($mailer)->call(['id' => $question->getId(), 'text' => 'Cena je [Sem doplňte cenu].']);
            self::fail('Text s místem k doplnění se nesmí odeslat.');
        } catch (McpToolException) {
        }

        self::assertNull($mailer->sent);
        self::assertFalse($question->isAnswered());
    }

    public function testReplyToOwnOrUnknownEmailIsToolError(): void
    {
        $own = $this->email('3', EmailDirection::OUTBOUND, 'Re: Volno', 'now -1 day');
        $tool = $this->replyTool(new CapturingMailer());

        foreach ([['id' => $own->getId(), 'text' => 'Dobrý den'], ['id' => 999999, 'text' => 'Dobrý den'], ['id' => $own->getId()]] as $arguments) {
            try {
                $tool->call($arguments);
                self::fail('Očekávaná chyba nástroje.');
            } catch (McpToolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMarkHandledDropsEmailFromUnanswered(): void
    {
        $ad = $this->email('1', EmailDirection::INBOUND, 'Nabídka prohlídek', 'now -1 day');
        $tool = static::getContainer()->get(MarkGuestEmailHandledTool::class);

        $tool->call(['id' => $ad->getId()]);
        $tool->call(['id' => $ad->getId()]);

        self::assertSame(0, $this->listTool()->call([])['count']);
    }

    public function testMarkHandledRefusesOwnReply(): void
    {
        $own = $this->email('3', EmailDirection::OUTBOUND, 'Re: Volno', 'now -1 day');

        $this->expectException(McpToolException::class);
        static::getContainer()->get(MarkGuestEmailHandledTool::class)->call(['id' => $own->getId()]);
    }

    private function email(string $id, EmailDirection $direction, string $subject, string $sentAt, string $body = 'Dobrý den', bool $answered = false, string $from = 'jana@example.com'): GuestEmail
    {
        $email = new GuestEmail($direction, $id . '@example.com', new MailAddress(null, $from), $subject, new \DateTimeImmutable($sentAt), $body);
        if ($answered) {
            $email->markAnswered();
        }
        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }

    private function listTool(): ListGuestEmailsTool
    {
        return static::getContainer()->get(ListGuestEmailsTool::class);
    }

    private function replyTool(CapturingMailer $mailer): ReplyToGuestEmailTool
    {
        $container = static::getContainer();
        $container->get(SettingRepository::class)->set(MailSettingsProvider::SENDER_EMAIL, 'info@example.com');
        $this->em->flush();
        $replier = new GuestEmailReplier($mailer, $container->get(GuestEmailFactory::class), $this->em, new MockClock());

        return new ReplyToGuestEmailTool($container->get(GuestEmailArgument::class), $replier);
    }

    private function readTool(): ReadGuestEmailTool
    {
        return static::getContainer()->get(ReadGuestEmailTool::class);
    }
}
