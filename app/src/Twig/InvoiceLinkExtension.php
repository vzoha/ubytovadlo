<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Invoice;
use App\Entity\InvoiceLink;
use App\Invoice\InvoiceLinks;
use App\Repository\InvoiceLinkRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Odkazy faktury pro detail rezervace:
 * {% for item in invoice_links(invoice) %}{{ item.link.expiresAt|date }} {{ item.active }}{% endfor %}.
 */
class InvoiceLinkExtension extends AbstractExtension
{
    public function __construct(
        private readonly InvoiceLinkRepository $repository,
        private readonly InvoiceLinks $links,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('invoice_links', $this->forInvoice(...)),
        ];
    }

    /** @return list<array{link: InvoiceLink, active: bool}> */
    public function forInvoice(Invoice $invoice): array
    {
        return array_map(
            fn (InvoiceLink $link): array => ['link' => $link, 'active' => $this->links->isActive($link)],
            $this->repository->findForInvoice($invoice),
        );
    }
}
