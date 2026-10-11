<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

/**
 * Reads an enum case out of a notification field.
 *
 * Shared by every enum on this side of the exchange, for two reasons that are
 * the same reason twice.
 *
 * It is **case-insensitive**, because the platform's own values are not
 * consistent about it - `Annulation` with a capital, `paiement` without,
 * `Refus banque` with a space - and matching them exactly is a guess about
 * which is a typo in the documentation and which is the wire.
 *
 * It returns **null rather than throwing**, because these values arrive in a
 * notification that has to be answered within fifteen seconds. A card network
 * this library has never heard of must not stop the acknowledgement; the raw
 * value stays available on the notification, and the seal - not the vocabulary
 * - is what decides whether to trust the message.
 */
trait FromWire
{
    public static function tryFromWire(string $value): ?static
    {
        $value = trim($value);

        foreach (self::cases() as $case) {
            if (strcasecmp((string) $case->value, $value) === 0) {
                return $case;
            }
        }

        return null;
    }
}
