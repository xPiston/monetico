<?php

declare(strict_types=1);

namespace Piston\Monetico\Security;

use Piston\Monetico\Exception\InvalidField;
use SensitiveParameter;

/**
 * The merchant security key, in the only form the seal can use.
 *
 * Monetico hands it over as 40 hexadecimal characters; RFC 2104 needs bytes.
 * The documentation calls the second form the "operational representation" and
 * is explicit that the first must be converted into 20 bytes before use. Doing
 * it anywhere else is the single most common integration bug: hashing with the
 * 40-character *text* produces a perfectly well-formed seal that the platform
 * rejects every time, with a message that blames the form.
 *
 * Historical note worth having in one place: the official PHP kit ran the key
 * through a routine (`getUsableKey`) that patched up its last two characters.
 * That routine only ever changes a key containing characters outside
 * [0-9A-Fa-f] - in other words, a key that is not hexadecimal at all. For
 * every key Monetico issues it is the identity, which is why this class is
 * plain `hex2bin` and the test suite proves the equivalence over the whole
 * key space rather than taking my word for it.
 */
final class SecurityKey
{
    private const LENGTH = 40;

    /** The 20 bytes the HMAC is keyed with. */
    private readonly string $bytes;

    private function __construct(#[SensitiveParameter] string $bytes)
    {
        $this->bytes = $bytes;
    }

    public static function fromHex(#[SensitiveParameter] string $hex): self
    {
        $hex = trim($hex);

        if (strlen($hex) !== self::LENGTH) {
            throw InvalidField::securityKey(sprintf('expected %d hexadecimal characters, got %d', self::LENGTH, strlen($hex)));
        }

        if (preg_match('/^[0-9A-Fa-f]{40}$/', $hex) !== 1) {
            throw InvalidField::securityKey('expected hexadecimal characters only');
        }

        $bytes = hex2bin($hex);

        // Unreachable after the checks above; kept so the type is honest
        // rather than suppressed.
        if ($bytes === false) {
            throw InvalidField::securityKey('could not be read as hexadecimal');
        }

        return new self($bytes);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * Keeps the key out of `var_dump()`, Symfony's dumper, Laravel's `dd()`
     * and anything else that walks an object's properties - which is most
     * error reporters.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['bytes' => '*** 20 bytes, not shown ***'];
    }
}
