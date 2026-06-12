<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VatPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VatPeriod>
 */
class VatPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VatPeriod::class);
    }

    public function findOrCreate(int $year, int $month): VatPeriod
    {
        $existing = $this->findOneBy(['year' => $year, 'month' => $month]);
        if ($existing !== null) {
            return $existing;
        }

        $period = new VatPeriod($year, $month);
        $this->getEntityManager()->persist($period);

        return $period;
    }

    /**
     * Vrátí množinu klíčů "YYYY-MM" pro období, která už mají filed_at vyplněné.
     * Hodí se pro hromadnou kontrolu (např. v dashboardu) bez N+1 dotazů.
     *
     * @return array<string, true>
     */
    public function findFiledKeySet(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.year', 'p.month')
            ->andWhere('p.filedAt IS NOT NULL')
            ->getQuery()
            ->getArrayResult();

        $keys = [];
        foreach ($rows as $row) {
            $keys[sprintf('%04d-%02d', $row['year'], $row['month'])] = true;
        }

        return $keys;
    }
}
