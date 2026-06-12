<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    public function findByExternalId(Channel $channel, string $externalId): ?Reservation
    {
        return $this->findOneBy(['channel' => $channel, 'externalId' => $externalId]);
    }

    public function findByMotopressExternalId(string $motopressExternalId): ?Reservation
    {
        return $this->findOneBy(['motopressExternalId' => $motopressExternalId]);
    }

    public function findByChannelAndCheckIn(Channel $channel, \DateTimeImmutable $checkIn): ?Reservation
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.channel = :channel')
            ->andWhere('r.checkIn = :checkIn')
            ->andWhere('r.motopressExternalId IS NULL')
            ->setParameter('channel', $channel)
            ->setParameter('checkIn', $checkIn)
            ->orderBy('r.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Rezervace s odjezdem v daném měsíci a vyplněnou provizí — vstup pro DPH za měsíc.
     * U Booking je DUZP poslední den měsíce odjezdu (faktura vychází z termínu odjezdu).
     * U Airbnb je DUZP datum check-outu jednotlivé rezervace.
     *
     * @return Reservation[]
     */
    public function findCommissionableByCheckoutMonth(int $year, int $month, ?Channel $channel = null): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to = $from->modify('last day of this month');

        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.checkOut BETWEEN :from AND :to')
            ->andWhere('r.commissionAmount IS NOT NULL')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.checkOut', 'ASC');

        if ($channel !== null) {
            $qb->andWhere('r.channel = :channel')->setParameter('channel', $channel);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Reservation[]
     */
    public function findAllWithCommission(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.commissionAmount IS NOT NULL')
            ->andWhere('r.checkOut IS NOT NULL')
            ->orderBy('r.checkOut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nadcházející pobyty pro plánování úklidu: ten, který právě probíhá (check_in <= today < check_out),
     * + ty s příjezdem v okně. Třídí podle check_in vzestupně.
     *
     * @return Reservation[]
     */
    public function findUpcoming(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status != :cancelled')
            ->andWhere('(r.checkIn BETWEEN :from AND :to) OR (r.checkOut BETWEEN :from AND :to) OR (r.checkIn <= :from AND r.checkOut >= :from)')
            ->setParameter('cancelled', ReservationStatus::CANCELLED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.checkIn', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rezervace s aspoň jedním potvrzeným cizincem (host vyplnil check-in) —
     * vstup pro Ubyport frontu. Stav nahlášení (k nahlášení / čeká na doručenku /
     * nahlášeno) určuje volající z polí rezervace + úplnosti údajů hostů.
     *
     * @return Reservation[]
     */
    public function findWithConfirmedForeigners(): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin(\App\Entity\GuestDocument::class, 'g', 'WITH', 'g.reservation = r')
            ->andWhere('g.isCzechCitizen = false')
            ->andWhere('g.confirmedAt IS NOT NULL')
            ->andWhere('r.status != :cancelled')
            ->setParameter('cancelled', ReservationStatus::CANCELLED)
            ->groupBy('r.id')
            ->orderBy('r.checkIn', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rezervace, které potřebují doplnit údaje (typicky OTA trigger bez hosta).
     *
     * @return Reservation[]
     */
    public function findNeedsDetails(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :needs')
            ->setParameter('needs', ReservationStatus::NEEDS_DETAILS)
            ->orderBy('r.checkIn', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rezervace, jejichž celý pobyt spadá do intervalu mezi dvěma odečty elektřiny.
     * Konvence: check_in >= readingFrom a check_out <= readingTo (tj. odečet udělaný
     * mimo pobyt). Cancelled vyloučeny.
     *
     * @return Reservation[]
     */
    public function findInInterval(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.checkIn >= :from')
            ->andWhere('r.checkOut <= :to')
            ->andWhere('r.status != :cancelled')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('cancelled', ReservationStatus::CANCELLED)
            ->orderBy('r.checkIn', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rezervace s vyplněnými údaji hosta (status != needs_details, není cancelled),
     * jejichž check_in spadá do dneška plus zadaného horizontu. Bez horního omezení
     * do minulosti — jednou nedotaženou fakturu má dashboard držet pingnutou napořád.
     *
     * Použito pro detekci chybějících faktur (porovnání s tabulkou invoice už dělá kontroler).
     *
     * @return Reservation[]
     */
    public function findInvoiceCandidatesUpToCheckIn(\DateTimeImmutable $checkInBefore): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.checkIn <= :horizon')
            ->andWhere('r.status NOT IN (:skip)')
            ->setParameter('horizon', $checkInBefore)
            ->setParameter('skip', [ReservationStatus::CANCELLED, ReservationStatus::NEEDS_DETAILS])
            ->orderBy('r.checkIn', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rezervace pro roční ekonomický přehled — podle roku příjezdu, bez zrušených
     * a bez nedoplněných (needs_details nemá cenu ani hosta, řádek by byl prázdný).
     *
     * @return Reservation[]
     */
    public function findForEconomicsYear(int $year): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.checkIn >= :from')
            ->andWhere('r.checkIn < :to')
            ->andWhere('r.status NOT IN (:skip)')
            ->setParameter('from', new \DateTimeImmutable(sprintf('%04d-01-01', $year)))
            ->setParameter('to', new \DateTimeImmutable(sprintf('%04d-01-01', $year + 1)))
            ->setParameter('skip', [ReservationStatus::CANCELLED, ReservationStatus::NEEDS_DETAILS])
            ->orderBy('r.checkIn', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Roky, ve kterých existuje aspoň jedna rezervace (pro výběr roku v přehledu).
     *
     * @return list<int>
     */
    public function findDistinctCheckInYears(): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('MIN(r.checkIn) AS minCheckIn', 'MAX(r.checkIn) AS maxCheckIn')
            ->getQuery()
            ->getSingleResult();

        if ($row['minCheckIn'] === null) {
            return [];
        }

        $min = (int) (new \DateTimeImmutable($row['minCheckIn']))->format('Y');
        $max = (int) (new \DateTimeImmutable($row['maxCheckIn']))->format('Y');

        return array_reverse(range($min, $max));
    }
}
