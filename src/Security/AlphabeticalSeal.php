<?php

declare(strict_types=1);

namespace Piston\Monetico\Security;

/**
 * The seal of version 3.0: every field, as `name=value`, joined by `*`, in
 * alphabetical order.
 *
 * Three details in that sentence are where integrations go wrong.
 *
 * **"Every field."** For a request, the documentation says all parameters the
 * platform recognises, valued or not. For a response it is firmer still: *all
 * parameters sent by our server, even the ones unknown or unused by your
 * server*. So this takes the array it is given and sorts it - it owns no list
 * of field names, and it is important that it never does. Monetico adds a
 * field with a random name and random content to every sandbox notification,
 * for the stated purpose of proving that merchants handle parameters they have
 * never seen. An implementation that rebuilds the string from the fields it
 * knows about passes every test it writes itself and fails that one.
 *
 * **"Alphabetical order"** means ASCII order, which is case sensitive: digits,
 * then upper case, then lower case. `TPE` therefore sorts before `date`, and
 * `MAC` before both. PHP's `ksort()` defaults to SORT_REGULAR, which compares
 * two numeric-looking keys as numbers; the sort here is byte comparison.
 *
 * **The MAC itself is not part of the string** it seals. It is dropped
 * whatever its case, because the platform documents `MAC` but there is no
 * reason to be strict about something we are removing.
 */
final class AlphabeticalSeal extends SealAlgorithm
{
    public const SEPARATOR = '*';

    public function name(): string
    {
        return 'alphabetical';
    }

    public function canonicalString(array $fields): string
    {
        $fields = array_filter(
            $fields,
            static fn (int|string $name): bool => strcasecmp((string) $name, 'MAC') !== 0,
            ARRAY_FILTER_USE_KEY,
        );

        uksort($fields, static fn (int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));

        $pairs = [];

        foreach ($fields as $name => $value) {
            $pairs[] = $name.'='.($value ?? '');
        }

        return implode(self::SEPARATOR, $pairs);
    }
}
