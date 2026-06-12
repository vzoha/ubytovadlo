<?php

declare(strict_types=1);

namespace App\Ubyport;

use App\Entity\GuestDocument;
use App\Entity\Reservation;

/**
 * Jeden řádek Ubyport fronty = jedna rezervace s jejími cizinci a stavem
 * nahlášení. UNL se vždy generuje za jednu rezervaci, proto je jednotkou
 * fronty rezervace, ne jednotlivý host.
 */
final readonly class UbyportRow
{
    public const STATE_TO_REPORT = 'to_report';
    public const STATE_INCOMPLETE = 'incomplete';
    public const STATE_AWAITING_RECEIPT = 'awaiting_receipt';
    public const STATE_REPORTED = 'reported';

    /**
     * @param list<GuestDocument> $foreigners potvrzení cizinci na rezervaci
     * @param list<string>        $missing    chybějící povinná pole pro UNL
     */
    public function __construct(
        public Reservation $reservation,
        public array $foreigners,
        public array $missing,
        public bool $isComplete,
        public string $state,
        public \DateTimeImmutable $deadline,
        public int $daysLeft,
        public string $deadlineState,
    ) {
    }

    /** Lhůta je relevantní, dokud není nahlášeno. */
    public function isOverdue(): bool
    {
        return $this->deadlineState === 'overdue'
            && \in_array($this->state, [self::STATE_TO_REPORT, self::STATE_INCOMPLETE], true);
    }
}
