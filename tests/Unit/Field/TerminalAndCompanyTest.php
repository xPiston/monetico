<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Field\CompanyCode;
use Piston\Monetico\Field\TerminalNumber;

final class TerminalAndCompanyTest extends TestCase
{
    public function test_a_terminal_is_seven_alphanumeric_characters(): void
    {
        $this->assertSame('1234567', TerminalNumber::of('1234567')->value());
        $this->assertSame('ab12CD3', TerminalNumber::of('ab12CD3')->value());
        $this->assertSame('1234567', (string) TerminalNumber::of(' 1234567 '));
    }

    #[DataProvider('invalidTerminals')]
    public function test_it_refuses_anything_else(string $value): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('expected 7 alphanumeric characters');

        TerminalNumber::of($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTerminals(): iterable
    {
        yield 'empty' => [''];
        yield 'six' => ['123456'];
        yield 'eight' => ['12345678'];
        yield 'with a dash' => ['12-4567'];
    }

    public function test_two_identical_terminals_are_the_same_terminal(): void
    {
        $this->assertTrue(TerminalNumber::of('1234567')->equals(TerminalNumber::of('1234567')));
        $this->assertFalse(TerminalNumber::of('1234567')->equals(TerminalNumber::of('7654321')));
    }

    public function test_a_company_code_is_alphanumeric(): void
    {
        $this->assertSame('monSite1', CompanyCode::of('monSite1')->value());
        $this->assertSame('monSite1', (string) CompanyCode::of('monSite1'));
    }

    #[DataProvider('invalidCompanyCodes')]
    public function test_it_refuses_an_impossible_company_code(string $value): void
    {
        $this->expectException(InvalidField::class);

        CompanyCode::of($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCompanyCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'with a space' => ['mon Site'];
        yield 'with an underscore' => ['mon_site'];
        yield 'too long' => [str_repeat('a', 65)];
    }
}
