<?php

declare(strict_types=1);

namespace Piston\Monetico\Security;

/**
 * Tries each seal algorithm until one matches.
 *
 * The order matters only for speed - a seal that matches, matches - and the
 * current algorithm is first. What matters is that *all* of them are tried
 * before a notification is called untrustworthy, because the alternative is
 * refusing a real payment for an instalment taken before the shop migrated.
 */
final class SealVerifier
{
    /** @var list<SealAlgorithm> */
    private readonly array $algorithms;

    public function __construct(SealAlgorithm ...$algorithms)
    {
        $this->algorithms = $algorithms === []
            ? [new AlphabeticalSeal, new LegacyPositionalSeal]
            : array_values($algorithms);
    }

    /** Only the current algorithm: for a shop that has never used the other. */
    public static function strict(): self
    {
        return new self(new AlphabeticalSeal);
    }

    /**
     * The algorithm whose seal matches, or null if none does.
     *
     * Returns the algorithm rather than a boolean so callers can log which one
     * carried a given notification - the only way to know when the last
     * pre-migration instalment has gone through and the legacy path can be
     * turned off.
     *
     * @param  array<array-key, string|int|float|null>  $fields
     */
    public function matching(array $fields, Seal $received, SecurityKey $key): ?SealAlgorithm
    {
        foreach ($this->algorithms as $algorithm) {
            if ($algorithm->matches($fields, $received, $key)) {
                return $algorithm;
            }
        }

        return null;
    }

    /** @return list<SealAlgorithm> */
    public function algorithms(): array
    {
        return $this->algorithms;
    }
}
