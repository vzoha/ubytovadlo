<?php

declare(strict_types=1);

namespace App\Email;

use App\Email\Dto\BookingTriggerData;

/**
 * Booking.com new-reservation e-mails carry no guest details — only the
 * reservation id and check-in date in the subject. This parser extracts
 * exactly that, so the IMAP poller can create a needs_details Reservation.
 */
class BookingTriggerParser
{
    private const FROM_ADDRESS = 'noreply@booking.com';

    private const CZECH_MONTHS = [
        'ledna' => 1, 'února' => 2, 'března' => 3, 'dubna' => 4,
        'května' => 5, 'června' => 6, 'července' => 7, 'srpna' => 8,
        'září' => 9, 'října' => 10, 'listopadu' => 11, 'prosince' => 12,
    ];

    private const SUBJECT_PATTERN = '/Nová rezervace!\s*\((?<id>\d+),\s+(?:pondělí|úterý|středa|čtvrtek|pátek|sobota|neděle)\s+(?<day>\d{1,2})\.\s+(?<month>ledna|února|března|dubna|května|června|července|srpna|září|října|listopadu|prosince)\s+(?<year>\d{4})\)/u';

    public function supports(EmailMessage $email): bool
    {
        if ($email->fromAddress !== null
            && stripos($email->fromAddress, self::FROM_ADDRESS) !== false) {
            return (bool) preg_match(self::SUBJECT_PATTERN, $email->subject);
        }

        return false;
    }

    public function parse(EmailMessage $email): BookingTriggerData
    {
        if (!preg_match(self::SUBJECT_PATTERN, $email->subject, $m)) {
            throw new \InvalidArgumentException('Subject does not match Booking new-reservation pattern.');
        }

        $month = self::CZECH_MONTHS[$m['month']];
        $checkIn = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $m['year'], $month, (int) $m['day']));

        return new BookingTriggerData(
            reservationId: $m['id'],
            checkIn: $checkIn,
        );
    }
}
