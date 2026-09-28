<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\InvoiceLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvoiceLink>
 */
class InvoiceLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceLink::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?InvoiceLink
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /** @return list<InvoiceLink> od nejnovějšího */
    public function findForInvoice(Invoice $invoice): array
    {
        return $this->findBy(['invoice' => $invoice], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
