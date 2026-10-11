<?php

declare(strict_types=1);

namespace Piston\Monetico\Security;

use Piston\Monetico\Exception\InvalidField;
use Stringable;

/**
 * A MAC seal: 40 hexadecimal characters.
 *
 * It exists as a type for one reason - comparison. The platform documents the
 * seal it sends in upper case and the seal it expects in lower case, and the
 * same payment can arrive either way depending on the service. A string
 * comparison is therefore wrong twice over: wrong on case, and wrong because
 * comparing a MAC with `===` leaks how many leading characters an attacker
 * guessed right. `equals()` normalises, then uses `hash_equals`.
 */
final class Seal implements Stringable
{
    private function __construct(private readonly string $hex) {}

    public static function fromHex(string $hex): self
    {
        $hex = trim($hex);

        if (preg_match('/^[0-9A-Fa-f]{40}$/', $hex) !== 1) {
            throw InvalidField::because('MAC', 'expected 40 hexadecimal characters', $hex);
        }

        return new self(strtolower($hex));
    }

    /** The seal as the form expects it: 40 lower-case hexadecimal characters. */
    public function value(): string
    {
        return $this->hex;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->hex, $other->hex);
    }

    public function __toString(): string
    {
        return $this->hex;
    }
}
