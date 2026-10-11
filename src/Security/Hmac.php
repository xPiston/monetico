<?php

declare(strict_types=1);

namespace Piston\Monetico\Security;

use SensitiveParameter;

/**
 * RFC 2104 HMAC-SHA1, which is what the platform specifies.
 *
 * SHA-1 is not a choice this library gets to make: the seal is verified by
 * Monetico's server, so the algorithm is theirs. It is worth being precise
 * about what that does and does not mean. HMAC-SHA1 is not broken - the
 * collision attacks on SHA-1 do not carry over to HMAC, which is why TLS and
 * RFC 2104 constructions outlived bare SHA-1 by years. What protects this
 * exchange is the secrecy of the 20-byte key, and that is the thing to guard.
 */
final class Hmac
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function sign(string $data, #[SensitiveParameter] SecurityKey $key): Seal
    {
        return Seal::fromHex(hash_hmac('sha1', $data, $key->bytes()));
    }
}
