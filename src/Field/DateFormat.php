<?php

declare(strict_types=1);

namespace Piston\Monetico\Field;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The three date formats on the wire, and the timezone question nobody asks
 * until a payment fails at midnight.
 *
 * The platform compares the dates it receives with its own clock: an order
 * form is good for about twelve hours, a capture or refund for twenty-four
 * either side. Those windows are wide enough to absorb a timezone mistake, so
 * a shop sending UTC never sees an error - it sees a dashboard where every
 * order is stamped two hours early in summer, and a support conversation about
 * a payment at "08:55" that the merchant remembers taking at eleven.
 *
 * Monetico is a French bank's platform and its dates are Paris local time, so
 * that is what is sent, converted rather than assumed. A shop with a reason to
 * send something else passes its own zone.
 */
final class DateFormat
{
    /** `date` on a payment form, a capture and a refund. */
    public const DATE_TIME = 'd/m/Y:H:i:s';

    /** `date_commande`, `date_remise`, `dateech1`..`dateech4`. */
    public const DAY = 'd/m/Y';

    /**
     * `date` in a notification.
     *
     * The platform's own prose and its own examples disagree about this one -
     * the field is described as `DD/MM/YYYY_at_HH:MM:SS` while the sample
     * payloads next to it read `05/12/2006_a_11:55:23` and the field table's
     * example reads `24/05/2019:10:00:25`. All three are accepted on the way
     * in; see parseNotification().
     */
    public const NOTIFICATION = 'd/m/Y_\a_H:i:s';

    public const PLATFORM_TIMEZONE = 'Europe/Paris';

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function platformTimezone(): DateTimeZone
    {
        return new DateTimeZone(self::PLATFORM_TIMEZONE);
    }

    public static function dateTime(DateTimeInterface $moment, ?DateTimeZone $timezone = null): string
    {
        return self::in($moment, $timezone)->format(self::DATE_TIME);
    }

    public static function day(DateTimeInterface $moment, ?DateTimeZone $timezone = null): string
    {
        return self::in($moment, $timezone)->format(self::DAY);
    }

    /**
     * Reads the date out of a notification, or returns null.
     *
     * Null rather than an exception: a notification has to be acknowledged
     * within fifteen seconds and the acknowledgement depends on the seal
     * alone. Throwing here would turn an unfamiliar date format into an
     * unanswered notification, a warning email to the merchant and a second
     * delivery of the same payment.
     */
    public static function parseNotification(string $raw, ?DateTimeZone $timezone = null): ?DateTimeImmutable
    {
        $timezone ??= self::platformTimezone();

        // The third is the shape the English translation describes. The
        // backslashes are not decoration: in a PHP date format `a` is the
        // meridiem and `t` the number of days in the month, so an unescaped
        // `_at_` parses nothing at all.
        foreach ([self::NOTIFICATION, self::DATE_TIME, 'd/m/Y_\\a\\t_H:i:s'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, trim($raw), $timezone);

            if ($parsed instanceof DateTimeImmutable) {
                return $parsed;
            }
        }

        return null;
    }

    private static function in(DateTimeInterface $moment, ?DateTimeZone $timezone): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone($timezone ?? self::platformTimezone());
    }
}
