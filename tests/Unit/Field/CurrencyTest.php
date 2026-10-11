<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Field\Currency;

final class CurrencyTest extends TestCase
{
    public function test_it_normalises_the_code(): void
    {
        $this->assertSame('EUR', Currency::of('eur')->code());
        $this->assertSame('EUR', Currency::of(' EUR ')->code());
        $this->assertSame('EUR', (string) Currency::euro());
    }

    public function test_most_currencies_have_two_decimals(): void
    {
        $this->assertSame(2, Currency::of('EUR')->decimals());
        $this->assertSame(2, Currency::of('USD')->decimals());
        $this->assertSame(2, Currency::of('CHF')->decimals());
    }

    #[DataProvider('withoutDecimals')]
    public function test_some_currencies_have_none(string $code): void
    {
        $this->assertSame(0, Currency::of($code)->decimals());
    }

    /** @return iterable<string, array{string}> */
    public static function withoutDecimals(): iterable
    {
        yield 'yen' => ['JPY'];
        yield 'won' => ['KRW'];
        yield 'CFA franc' => ['XOF'];
        yield 'Icelandic krona' => ['ISK'];
    }

    /**
     * The platform's amount format stops at two decimals, so a currency whose
     * smallest unit is a thousandth cannot be expressed in it at all. Rounding
     * somebody's money silently is the wrong answer.
     */
    #[DataProvider('withThreeDecimals')]
    public function test_a_currency_with_three_decimals_is_refused_by_name(string $code): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('at most two decimals');

        Currency::of($code);
    }

    /** @return iterable<string, array{string}> */
    public static function withThreeDecimals(): iterable
    {
        yield 'Kuwaiti dinar' => ['KWD'];
        yield 'Tunisian dinar' => ['TND'];
        yield 'Omani rial' => ['OMR'];
    }

    #[DataProvider('notCurrencies')]
    public function test_it_refuses_what_is_not_a_code(string $code): void
    {
        $this->expectException(InvalidField::class);

        Currency::of($code);
    }

    /** @return iterable<string, array{string}> */
    public static function notCurrencies(): iterable
    {
        yield 'empty' => [''];
        yield 'two letters' => ['EU'];
        yield 'four letters' => ['EURO'];
        yield 'a symbol' => ['€'];
        yield 'digits' => ['978'];
    }

    public function test_two_identical_codes_are_the_same_currency(): void
    {
        $this->assertTrue(Currency::of('EUR')->equals(Currency::euro()));
        $this->assertFalse(Currency::of('EUR')->equals(Currency::of('USD')));
    }
}
