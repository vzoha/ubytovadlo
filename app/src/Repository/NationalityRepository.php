<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Nationality;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Nationality>
 */
class NationalityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Nationality::class);
    }

    /**
     * @return Nationality[]
     */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('n')
            ->orderBy('n.nameCs', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
