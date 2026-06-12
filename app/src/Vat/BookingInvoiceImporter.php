<?php

declare(strict_types=1);

namespace App\Vat;

use App\Email\EmailAttachment;
use App\Email\EmailMessage;
use App\Entity\BookingMonthlyInvoice;
use App\Entity\EmailLog;
use App\Repository\BookingMonthlyInvoiceRepository;
use App\Storage\PdfStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Importér měsíční Booking provize faktury z e-mailové přílohy.
 *
 * Booking posílá z noreply@booking.com mail s předmětem "Booking.com Invoice 12345"
 * a PDF přílohou. Tento importér PDF uloží na disk, sparsuje a založí
 * BookingMonthlyInvoice řádku. Volá se z EmailDispatcheru.
 */
class BookingInvoiceImporter
{
    private const FROM_ADDRESS = 'noreply@booking.com';
    private const SUBJECT_PATTERN = '/Booking\\.com Invoice\\s+(?<id>\\d+)/u';

    public function __construct(
        private readonly BookingInvoiceParser $parser,
        private readonly BookingMonthlyInvoiceRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
        private readonly PdfStorage $pdfStorage,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(EmailMessage $email): bool
    {
        if ($email->fromAddress === null || stripos($email->fromAddress, self::FROM_ADDRESS) === false) {
            return false;
        }

        return (bool) preg_match(self::SUBJECT_PATTERN, $email->subject);
    }

    public function import(EmailMessage $email, ?EmailLog $sourceEmail = null): BookingMonthlyInvoice
    {
        $pdf = $this->findPdfAttachment($email);
        if ($pdf === null) {
            throw new \RuntimeException('Booking Invoice e-mail neobsahuje PDF přílohu');
        }

        $data = $this->parser->parseContent($pdf->content);

        $existing = $this->repository->findByInvoiceNumber($data->invoiceNumber);
        if ($existing !== null) {
            $this->logger->info('Booking invoice already imported, skipping', [
                'invoiceNumber' => $data->invoiceNumber,
            ]);

            return $existing;
        }

        $pdfPath = $this->savePdf($pdf, $data->invoiceNumber);

        $invoice = new BookingMonthlyInvoice(
            invoiceNumber: $data->invoiceNumber,
            issuedAt: $data->issuedAt,
            periodFrom: $data->periodFrom,
            periodTo: $data->periodTo,
            roomSales: $data->roomSales,
            commission: $data->commission,
            totalPayable: $data->totalPayable,
            pdfPath: $pdfPath,
        );
        $invoice->setCurrency($data->currency);
        $invoice->setPaymentFee($data->paymentFee);
        $invoice->setBookingExchangeRate($data->bookingExchangeRate);
        if ($sourceEmail !== null) {
            $invoice->setSourceEmail($sourceEmail);
        }

        $this->em->persist($invoice);

        return $invoice;
    }

    private function findPdfAttachment(EmailMessage $email): ?EmailAttachment
    {
        foreach ($email->attachments as $attachment) {
            if (stripos($attachment->contentType, 'pdf') !== false
                || str_ends_with(strtolower($attachment->filename), '.pdf')) {
                return $attachment;
            }
        }

        return null;
    }

    private function savePdf(EmailAttachment $pdf, string $invoiceNumber): string
    {
        $dir = $this->projectDir . '/var/invoices/booking';
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create directory %s', $dir));
        }
        $path = $dir . '/invoice-' . $invoiceNumber . '.pdf';
        if (file_put_contents($path, $pdf->content) === false) {
            throw new \RuntimeException(sprintf('Cannot write PDF to %s', $path));
        }

        return $this->pdfStorage->relative($path);
    }
}
