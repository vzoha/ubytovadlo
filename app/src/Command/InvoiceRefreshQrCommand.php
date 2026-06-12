<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Invoice\InvoiceService;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:invoice:refresh-qr', description: 'Přepočítá SPAYD QR payload pro všechny faktury, které ho mají.')]
class InvoiceRefreshQrCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceService $invoiceService,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->invoices->findAll() as $invoice) {
            if ($invoice->getQrPayload() === null) {
                continue;
            }
            $this->invoiceService->refreshBankQr($invoice);
            $output->writeln(sprintf('%s → %s', $invoice->getNumber(), $invoice->getQrPayload()));
        }
        $this->em->flush();

        return Command::SUCCESS;
    }
}
