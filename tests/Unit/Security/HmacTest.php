<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Security\Hmac;
use Piston\Monetico\Security\SecurityKey;

/**
 * The seal is RFC 2104 HMAC-SHA1, so the thing to test it against is RFC 2202,
 * which publishes test vectors for exactly that.
 *
 * Only the vectors with a 20-byte key are usable here, because a Monetico key
 * is 40 hexadecimal characters and nothing else - which is the point: these
 * check the key derivation and the HMAC together, against numbers published by
 * somebody else.
 */
final class HmacTest extends TestCase
{
    #[DataProvider('rfc2202Vectors')]
    public function test_it_matches_the_published_vectors(string $keyHex, string $data, string $expected): void
    {
        $seal = Hmac::sign($data, SecurityKey::fromHex($keyHex));

        $this->assertSame($expected, $seal->value());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function rfc2202Vectors(): iterable
    {
        yield 'case 1' => [
            str_repeat('0b', 20),
            'Hi There',
            'b617318655057264e28bc0b6fb378c8ef146be00',
        ];

        yield 'case 3' => [
            str_repeat('aa', 20),
            str_repeat(hex2bin('dd') ?: '', 50),
            '125d7342b9ac11cd91a39af48aa17b4f63f175d3',
        ];

        yield 'case 5' => [
            str_repeat('0c', 20),
            'Test With Truncation',
            '4c1a03424b55e07fe7f27be1d58bb9324a9a5a04',
        ];
    }

    public function test_it_produces_forty_lower_case_hexadecimal_characters(): void
    {
        $seal = Hmac::sign('anything', SecurityKey::fromHex(str_repeat('ab', 20)));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $seal->value());
    }

    public function test_a_different_key_gives_a_different_seal(): void
    {
        $data = 'TPE=1234567*montant=10EUR';

        $this->assertNotSame(
            Hmac::sign($data, SecurityKey::fromHex(str_repeat('ab', 20)))->value(),
            Hmac::sign($data, SecurityKey::fromHex(str_repeat('cd', 20)))->value(),
        );
    }
}
