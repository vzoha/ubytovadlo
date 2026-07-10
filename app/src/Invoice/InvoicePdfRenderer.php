<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Config\LogoStorage;
use App\Entity\Invoice;
use App\Storage\PdfStorage;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Twig\Environment;

class InvoicePdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly IssuerProfileProvider $issuerProvider,
        private readonly string $projectDir,
        private readonly PdfStorage $pdfStorage,
        private readonly LogoStorage $logo,
    ) {
    }

    /**
     * Vykreslí PDF a uloží ho do var/invoices/RRRR/RRRR###.pdf. Vrací relativní
     * cestu (vůči projectDir) pro uložení do DB.
     */
    public function renderToFile(Invoice $invoice): string
    {
        $issuer = $this->issuerProvider->current();
        $html = $this->twig->render('invoice/pdf.html.twig', [
            'invoice' => $invoice,
            'issuer' => $issuer,
            'vatRecap' => InvoiceVatRecap::fromInvoice($invoice),
            'logoPath' => $this->logo->exists() ? $this->logo->absolutePath() : null,
        ]);

        $dir = sprintf('%s/var/invoices/%04d', $this->projectDir, $invoice->getSeriesYear());
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Nelze vytvořit adresář %s', $dir));
        }

        $path = $dir . '/' . $invoice->getNumber() . '.pdf';

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'dejavusans',
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_top' => 18,
            'margin_bottom' => 16,
            'tempDir' => $this->projectDir . '/var/mpdf',
        ]);
        $mpdf->SetTitle('Faktura ' . $invoice->getNumber());
        $mpdf->SetAuthor($issuer->name);
        $mpdf->SetHTMLHeader('');
        $mpdf->SetHTMLFooter('');

        $css = file_get_contents($this->projectDir . '/templates/invoice/pdf.css');
        if ($css !== false) {
            $mpdf->WriteHTML($css, HTMLParserMode::HEADER_CSS);
        }
        $mpdf->WriteHTML($html, HTMLParserMode::HTML_BODY);
        $mpdf->Output($path, Destination::FILE);

        return $this->pdfStorage->relative($path);
    }
}
