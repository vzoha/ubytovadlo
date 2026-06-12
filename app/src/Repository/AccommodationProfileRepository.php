<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccommodationProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccommodationProfile>
 */
class AccommodationProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccommodationProfile::class);
    }

    public function getSingleton(): ?AccommodationProfile
    {
        return $this->createQueryBuilder('p')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
