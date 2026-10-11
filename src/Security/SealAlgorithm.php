<?php

declare(strict_types=1);

namespace Piston\Monetico\Security;

/**
 * How a message becomes the string the seal is computed over.
 *
 * There are two such strings in production at any time - see
 * AlphabeticalSeal and LegacyPositionalSeal - and splitting them from the
 * HMAC itself is what keeps the signing and the comparing in one place:
 * a subclass only has to say how the fields are laid out.
 */
abstract class SealAlgorithm
{
    /**
     * The exact string, byte for byte, that gets hashed.
     *
     * Exposed rather than kept private because it is the only thing worth
     * looking at when the platform rejects a seal: a diff between this string
     * and the one in the documentation finds the problem in seconds.
     *
     * @param  array<array-key, string|int|float|null>  $fields
     */
    abstract public function canonicalString(array $fields): string;

    /** A short name, for logs and for telling the two algorithms apart. */
    abstract public function name(): string;

    /** @param array<array-key, string|int|float|null> $fields */
    final public function seal(array $fields, SecurityKey $key): Seal
    {
        return Hmac::sign($this->canonicalString($fields), $key);
    }

    /** @param array<array-key, string|int|float|null> $fields */
    final public function matches(array $fields, Seal $received, SecurityKey $key): bool
    {
        return $this->seal($fields, $key)->equals($received);
    }
}
