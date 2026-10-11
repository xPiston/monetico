<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Security\SecurityKey;

final class SecurityKeyTest extends TestCase
{
    public function test_it_converts_the_forty_hexadecimal_characters_into_twenty_bytes(): void
    {
        $key = SecurityKey::fromHex('0123456789ABCDEF0123456789ABCDEF01234567');

        $this->assertSame(20, strlen($key->bytes()));
        $this->assertSame('0123456789abcdef0123456789abcdef01234567', bin2hex($key->bytes()));
    }

    public function test_it_accepts_either_case(): void
    {
        $upper = SecurityKey::fromHex('0123456789ABCDEF0123456789ABCDEF01234567');
        $lower = SecurityKey::fromHex('0123456789abcdef0123456789abcdef01234567');

        $this->assertSame($upper->bytes(), $lower->bytes());
    }

    public function test_it_ignores_surrounding_whitespace(): void
    {
        // The key arrives in a file, and a trailing newline is the single most
        // common thing to find at the end of one.
        $key = SecurityKey::fromHex("  0123456789ABCDEF0123456789ABCDEF01234567\n");

        $this->assertSame(20, strlen($key->bytes()));
    }

    #[DataProvider('invalidKeys')]
    public function test_it_refuses_anything_that_is_not_a_key(string $hex, string $expectedMessage): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage($expectedMessage);

        SecurityKey::fromHex($hex);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => ['', 'expected 40 hexadecimal characters, got 0'];
        yield 'too short' => [str_repeat('a', 39), 'expected 40 hexadecimal characters, got 39'];
        yield 'too long' => [str_repeat('a', 41), 'expected 40 hexadecimal characters, got 41'];
        yield 'not hexadecimal' => [str_repeat('z', 40), 'expected hexadecimal characters only'];
        yield 'forty characters of base64' => ['QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIz', 'expected hexadecimal characters only'];
    }

    public function test_the_message_never_carries_the_key(): void
    {
        // This exception ends up in a log, and the key is the one secret in
        // the whole protocol.
        try {
            SecurityKey::fromHex('0123456789ABCDEF0123456789ABCDEF0123456');
            $this->fail('a 39-character key should have been refused');
        } catch (InvalidField $e) {
            $this->assertStringNotContainsString('0123456789', $e->getMessage());
        }
    }

    public function test_it_hides_its_bytes_from_a_dump(): void
    {
        $key = SecurityKey::fromHex('0123456789ABCDEF0123456789ABCDEF01234567');

        $dumped = print_r($key, true);

        $this->assertStringNotContainsString(hex2bin('0123456789abcdef') ?: 'x', $dumped);
        $this->assertStringContainsString('not shown', $dumped);
    }

    /**
     * The claim this class makes in its own docblock, checked rather than
     * asserted: for every key that is actually hexadecimal, the historical
     * `getUsableKey` routine from the official PHP kit and a plain `hex2bin`
     * agree. The routine only ever changes the last two characters, so the
     * interesting part of the key space is exhaustible.
     */
    public function test_it_agrees_with_the_historical_key_transformation_on_every_valid_key(): void
    {
        $alphabet = str_split('0123456789abcdefABCDEF');

        foreach ($alphabet as $penultimate) {
            foreach ($alphabet as $last) {
                $hex = str_repeat('0', 38).$penultimate.$last;

                $this->assertSame(
                    self::historicalUsableKey($hex),
                    SecurityKey::fromHex($hex)->bytes(),
                    'the two disagree for a key ending in '.$penultimate.$last,
                );
            }
        }
    }

    public function test_it_agrees_with_the_historical_key_transformation_on_random_keys(): void
    {
        $alphabet = '0123456789abcdefABCDEF';

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $hex = '';

            for ($position = 0; $position < 40; $position++) {
                $hex .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $this->assertSame(self::historicalUsableKey($hex), SecurityKey::fromHex($hex)->bytes());
        }
    }

    /**
     * The routine as the official kit shipped it, with the one change needed
     * to run it at all on a current PHP: `ord()` on a four-character string is
     * deprecated since 8.1, so it reads the first byte explicitly.
     */
    private static function historicalUsableKey(string $key): string
    {
        $hexStrKey = substr($key, 0, 38);
        $hexFinal = substr($key, 38, 2).'00';

        $firstByte = ord($hexFinal[0]);

        if ($firstByte > 70 && $firstByte < 97) {
            $hexStrKey .= chr($firstByte - 23).$hexFinal[1];
        } elseif ($hexFinal[1] === 'M') {
            $hexStrKey .= $hexFinal[0].'0';
        } else {
            $hexStrKey .= substr($hexFinal, 0, 2);
        }

        $packed = pack('H*', $hexStrKey);

        return $packed;
    }
}
