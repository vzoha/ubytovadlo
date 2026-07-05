<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Connector;
use App\Enum\ConnectorType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Connector>
 */
class ConnectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Connector::class);
    }

    /**
     * Řádek konektoru, případně nový (default zapnuto) — konektory se nezakládají
     * migrací, ale líně při prvním dotyku. Nový řádek se rovnou zapíše, aby ho
     * další getOrCreate v témže požadavku našel (jinak by vznikl duplikát dřív,
     * než se cokoli flushne).
     */
    public function getOrCreate(ConnectorType $type): Connector
    {
        $connector = $this->findOneBy(['type' => $type]);
        if ($connector === null) {
            $connector = new Connector($type);
            $this->getEntityManager()->persist($connector);
            $this->getEntityManager()->flush();
        }

        return $connector;
    }
}
