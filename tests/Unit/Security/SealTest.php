<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Security\Seal;

final class SealTest extends TestCase
{
    public function test_it_normalises_to_lower_case(): void
    {
        // The platform documents the seal it sends in upper case and the one
        // it expects in lower case.
        $seal = Seal::fromHex('F97861E0F3E296B7EECE2CFD86DC46C43AC88049');

        $this->assertSame('f97861e0f3e296b7eece2cfd86dc46c43ac88049', $seal->value());
        $this->assertSame('f97861e0f3e296b7eece2cfd86dc46c43ac88049', (string) $seal);
    }

    public function test_two_seals_written_in_different_cases_are_equal(): void
    {
        $sent = Seal::fromHex('F97861E0F3E296B7EECE2CFD86DC46C43AC88049');
        $computed = Seal::fromHex('f97861e0f3e296b7eece2cfd86dc46c43ac88049');

        $this->assertTrue($sent->equals($computed));
        $this->assertTrue($computed->equals($sent));
    }

    public function test_a_seal_that_differs_by_one_character_is_not_equal(): void
    {
        $this->assertFalse(
            Seal::fromHex(str_repeat('a', 40))->equals(Seal::fromHex(str_repeat('a', 39).'b')),
        );
    }

    #[DataProvider('invalidSeals')]
    public function test_it_refuses_anything_that_is_not_a_seal(string $value): void
    {
        $this->expectException(InvalidField::class);

        Seal::fromHex($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSeals(): iterable
    {
        yield 'empty' => [''];
        yield 'truncated' => [str_repeat('a', 39)];
        yield 'too long' => [str_repeat('a', 41)];
        yield 'not hexadecimal' => [str_repeat('g', 40)];
        yield 'with a space inside' => [str_repeat('a', 20).' '.str_repeat('a', 19)];
    }

    public function test_it_tolerates_surrounding_whitespace(): void
    {
        $this->assertSame(
            str_repeat('a', 40),
            Seal::fromHex(' '.str_repeat('a', 40).' ')->value(),
        );
    }
}
