<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Invoice\InvoicePdfRenderer;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:invoice:regenerate-pdf', description: 'Přegeneruje PDF pro existující fakturu (nebo všechny pokud bez argumentu).')]
class InvoiceRegeneratePdfCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoicePdfRenderer $renderer,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'ID konkrétní faktury; prázdné = vše.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        $invoices = $id !== null ? array_filter([$this->invoices->find((int) $id)]) : $this->invoices->findAll();

        foreach ($invoices as $invoice) {
            $path = $this->renderer->renderToFile($invoice);
            $invoice->setPdfPath($path);
            $output->writeln(sprintf('%s → %s', $invoice->getNumber(), $path));
        }
        $this->em->flush();

        return Command::SUCCESS;
    }
}
