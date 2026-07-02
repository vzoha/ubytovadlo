<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Email;

use App\Cashflow\IncomeUpserter;
use App\Email\AirbnbPayoutParser;
use App\Email\AirbnbReservationParser;
use App\Email\BookingTriggerParser;
use App\Email\CsPaymentParser;
use App\Email\EmailDispatcher;
use App\Email\EmailMessage;
use App\Email\EmlReader;
use App\Entity\EmailLog;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\EmailLogStatus;
use App\Enum\ReservationStatus;
use App\Payment\PaymentProcessor;
use App\Payment\PaymentResult;
use App\Repository\BookingMonthlyInvoiceRepository;
use App\Repository\EmailLogRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;
use App\Storage\PdfStorage;
use App\Vat\BookingInvoiceImporter;
use App\Vat\BookingInvoiceParser;
use Doctrine\DBAL\Driver\PDO\Exception as PdoException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class EmailDispatcherTest extends TestCase
{
    private EmailLogRepository&MockObject $emailLogs;
    private ReservationRepository&MockObject $reservations;
    private InvoiceRepository&MockObject $invoices;
    private PaymentProcessor&MockObject $paymentProcessor;
    private EntityManagerInterface&MockObject $em;
    private EmailDispatcher $dispatcher;
    private EmlReader $reader;

    protected function setUp(): void
    {
        $this->emailLogs = $this->createMock(EmailLogRepository::class);
        $this->reservations = $this->createMock(ReservationRepository::class);
        $this->invoices = $this->createMock(InvoiceRepository::class);
        $this->paymentProcessor = $this->createMock(PaymentProcessor::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        // wrapInTransaction by default just runs the closure.
        $this->em->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $fn) => $fn());

        $bookingInvoices = $this->createMock(BookingMonthlyInvoiceRepository::class);
        $bookingInvoiceImporter = new BookingInvoiceImporter(
            new BookingInvoiceParser(),
            $bookingInvoices,
            $this->em,
            sys_get_temp_dir(),
            new PdfStorage(sys_get_temp_dir()),
            new NullLogger(),
        );

        $this->dispatcher = new EmailDispatcher(
            $this->emailLogs,
            $this->reservations,
            new AirbnbReservationParser(),
            new AirbnbPayoutParser(),
            new BookingTriggerParser(),
            $bookingInvoiceImporter,
            new CsPaymentParser(),
            $this->paymentProcessor,
            $this->invoices,
            $this->createMock(IncomeUpserter::class),
            $this->em,
            new NullLogger(),
        );

        $this->reader = new EmlReader();
    }

    public function testReturnsExistingLogWhenMessageIdAlreadySeen(): void
    {
        $existing = new EmailLog('<dup@example.com>', new \DateTimeImmutable());
        $this->emailLogs->expects(self::once())
            ->method('findByMessageId')
            ->with('<dup@example.com>')
            ->willReturn($existing);

        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('wrapInTransaction');

        $email = new EmailMessage(
            messageId: '<dup@example.com>',
            fromAddress: 'foo@example.com',
            subject: 'whatever',
            date: new \DateTimeImmutable(),
            textBody: '',
        );

        self::assertSame($existing, $this->dispatcher->dispatch($email));
    }

    public function testIgnoresEmailWhenNoParserMatches(): void
    {
        $this->emailLogs->method('findByMessageId')->willReturn(null);
        $this->em->expects(self::once())->method('persist');

        $email = new EmailMessage(
            messageId: '<unknown@example.com>',
            fromAddress: 'noreply@randomsite.com',
            subject: 'Newsletter',
            date: new \DateTimeImmutable(),
            textBody: 'foo',
        );

        $log = $this->dispatcher->dispatch($email);

        self::assertSame(EmailLogStatus::IGNORED, $log->getStatus());
        self::assertSame('No parser matched', $log->getError());
    }

    public function testCreatesNewAirbnbReservationWithParsedData(): void
    {
        $this->emailLogs->method('findByMessageId')->willReturn(null);
        $this->reservations->method('findByExternalId')->willReturn(null);

        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });

        $email = $this->reader->fromFile(__DIR__ . '/../Fixtures/Airbnb/rezervace-potvrzena-petr-novak-pijede-3-9.eml');
        $log = $this->dispatcher->dispatch($email);

        self::assertSame(EmailLogStatus::PROCESSED, $log->getStatus());
        $reservation = $log->getReservation();
        self::assertInstanceOf(Reservation::class, $reservation);
        self::assertSame(Channel::AIRBNB, $reservation->getChannel());
        self::assertSame('HMABCD12EF', $reservation->getExternalId());
        self::assertSame('Petr Novák', $reservation->getGuestName());
        self::assertSame(ReservationStatus::NEEDS_DETAILS, $reservation->getStatus());
        self::assertSame('14000.00', $reservation->getPriceTotal());
        self::assertContains($reservation, $persisted);
    }

    public function testDoesNotOverwriteExistingReservationAfterConfirmation(): void
    {
        $this->emailLogs->method('findByMessageId')->willReturn(null);

        $existing = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('2026-09-07'));
        $existing->setExternalId('HMABCD12EF');
        $existing->setGuestName('Manually Edited Name');
        $existing->setGuestStreet('Some street 1');
        $existing->setGuestCity('Praha');
        $existing->setStatus(ReservationStatus::CONFIRMED);

        $reflection = new \ReflectionProperty($existing, 'id');
        $reflection->setValue($existing, 42);

        $this->reservations->method('findByExternalId')->willReturn($existing);

        $email = $this->reader->fromFile(__DIR__ . '/../Fixtures/Airbnb/rezervace-potvrzena-petr-novak-pijede-3-9.eml');
        $this->dispatcher->dispatch($email);

        self::assertSame('Manually Edited Name', $existing->getGuestName());
        self::assertSame('Some street 1', $existing->getGuestStreet());
        self::assertSame('Praha', $existing->getGuestCity());
        self::assertSame(ReservationStatus::CONFIRMED, $existing->getStatus());
    }

    public function testUpdatesReservationStillInNeedsDetails(): void
    {
        $this->emailLogs->method('findByMessageId')->willReturn(null);

        $existing = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('2026-09-07'));
        $existing->setExternalId('HMABCD12EF');
        $existing->setGuestName('stale');

        $reflection = new \ReflectionProperty($existing, 'id');
        $reflection->setValue($existing, 99);

        $this->reservations->method('findByExternalId')->willReturn($existing);

        $email = $this->reader->fromFile(__DIR__ . '/../Fixtures/Airbnb/rezervace-potvrzena-petr-novak-pijede-3-9.eml');
        $this->dispatcher->dispatch($email);

        self::assertSame('Petr Novák', $existing->getGuestName());
        self::assertSame(ReservationStatus::NEEDS_DETAILS, $existing->getStatus());
    }

    public function testAppliesAirbnbPayoutToReservationAndInvoice(): void
    {
        $this->emailLogs->method('findByMessageId')->willReturn(null);

        $reservation = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('2026-05-28'));
        $reservation->setExternalId('HMMNOP56QR');
        $this->reservations->method('findByExternalId')->willReturn($reservation);

        $invoice = $this->createMock(\App\Entity\Invoice::class);
        $invoice->method('isPaid')->willReturn(false);
        $invoice->expects(self::once())
            ->method('setPaidAt')
            ->with(self::callback(static fn (\DateTimeImmutable $d) => $d->format('Y-m-d') === '2026-05-27'));
        $this->invoices->method('findForReservation')->willReturn([$invoice]);

        $email = $this->reader->fromFile(__DIR__ . '/../Fixtures/Airbnb/poslali-jsme-ti-vyplatu-eva-markova-2500.eml');
        $log = $this->dispatcher->dispatch($email);

        self::assertSame(EmailLogStatus::PROCESSED, $log->getStatus());
        self::assertSame('2500.00', $reservation->getPayoutAmount());
        self::assertSame('2026-05-27', $reservation->getPayoutSentAt()?->format('Y-m-d'));
        self::assertSame('G-XY12345678901', $reservation->getPayoutReference());
    }

    public function testIgnoresPayoutForUnknownReservation(): void
    {
        $this->emailLogs->method('findByMessageId')->willReturn(null);
        $this->reservations->method('findByExternalId')->willReturn(null);

        $email = $this->reader->fromFile(__DIR__ . '/../Fixtures/Airbnb/poslali-jsme-ti-vyplatu-eva-markova-2500.eml');
        $log = $this->dispatcher->dispatch($email);

        self::assertSame(EmailLogStatus::IGNORED, $log->getStatus());
        self::assertStringContainsString('HMMNOP56QR', (string) $log->getError());
    }

    public function testRoutesCsPaymentToProcessorAndMarksProcessed(): void
    {
        $this->emailLogs->method('findByMessageId')->willReturn(null);
        $this->em->expects(self::once())->method('persist');

        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-07-10'));
        $this->paymentProcessor->expects(self::once())
            ->method('process')
            ->willReturn(PaymentResult::matched($reservation));

        $email = $this->reader->fromFile(__DIR__ . '/../Fixtures/CS/prisla-platba-zaloha.eml');
        $log = $this->dispatcher->dispatch($email);

        self::assertSame(EmailLogStatus::PROCESSED, $log->getStatus());
        self::assertSame($reservation, $log->getReservation());
    }

    public function testMarksCsPaymentIgnoredWhenProcessorReturnsUnmatched(): void
    {
        $this->emailLogs->method('findByMessageId')->willReturn(null);

        $this->paymentProcessor->expects(self::once())
            ->method('process')
            ->willReturn(PaymentResult::unmatched('Platba VS 9999 bez navázané rezervace.'));

        $email = $this->reader->fromFile(__DIR__ . '/../Fixtures/CS/prisla-platba-zaloha.eml');
        $log = $this->dispatcher->dispatch($email);

        self::assertSame(EmailLogStatus::IGNORED, $log->getStatus());
        self::assertStringContainsString('9999', (string) $log->getError());
    }

    public function testRecordsErrorWhenParserThrows(): void
    {
        $airbnbParser = $this->createMock(AirbnbReservationParser::class);
        $airbnbParser->method('supports')->willReturn(true);
        $airbnbParser->method('parse')->willThrowException(new \RuntimeException('parser exploded'));

        $bookingInvoiceImporter = new BookingInvoiceImporter(
            new BookingInvoiceParser(),
            $this->createMock(BookingMonthlyInvoiceRepository::class),
            $this->em,
            sys_get_temp_dir(),
            new PdfStorage(sys_get_temp_dir()),
            new NullLogger(),
        );

        $dispatcher = new EmailDispatcher(
            $this->emailLogs,
            $this->reservations,
            $airbnbParser,
            new AirbnbPayoutParser(),
            new BookingTriggerParser(),
            $bookingInvoiceImporter,
            new CsPaymentParser(),
            $this->paymentProcessor,
            $this->invoices,
            $this->createMock(IncomeUpserter::class),
            $this->em,
            new NullLogger(),
        );

        $this->emailLogs->method('findByMessageId')->willReturn(null);
        $this->em->expects(self::once())->method('persist');

        $email = new EmailMessage(
            messageId: '<broken@example.com>',
            fromAddress: 'automated@airbnb.com',
            subject: 'whatever',
            date: new \DateTimeImmutable(),
            textBody: '',
        );

        $log = $dispatcher->dispatch($email);

        self::assertSame(EmailLogStatus::ERROR, $log->getStatus());
        self::assertSame('parser exploded', $log->getError());
    }

    public function testRecoversFromConcurrentInsertOnUniqueConstraint(): void
    {
        $existing = new EmailLog('<race@example.com>', new \DateTimeImmutable());

        $this->emailLogs->method('findByMessageId')->willReturnOnConsecutiveCalls(null, $existing);

        $this->em->method('wrapInTransaction')
            ->willReturnCallback(function (): never {
                throw new UniqueConstraintViolationException(PdoException::new(new \PDOException('duplicate')), null);
            });
        $this->em->expects(self::once())->method('clear');

        $email = new EmailMessage(
            messageId: '<race@example.com>',
            fromAddress: 'noreply@booking.com',
            subject: 'whatever',
            date: new \DateTimeImmutable(),
            textBody: '',
        );

        self::assertSame($existing, $this->dispatcher->dispatch($email));
    }
}
