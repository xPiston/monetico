<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\Currency;

final class AmountTest extends TestCase
{
    public function test_it_writes_minor_units_the_way_the_platform_reads_them(): void
    {
        $this->assertSame('62.73EUR', Amount::fromMinor(6273, 'EUR')->toMonetico());
        $this->assertSame('0.05EUR', Amount::fromMinor(5, 'EUR')->toMonetico());
        $this->assertSame('100.00EUR', Amount::fromMinor(10000, 'EUR')->toMonetico());
        $this->assertSame('0.00EUR', Amount::zero('EUR')->toMonetico());
    }

    public function test_a_currency_without_minor_units_gets_no_decimal_point(): void
    {
        // 1000 yen is 1000, not 10.00 - and sending the second would charge a
        // customer a hundredth of what they owe.
        $this->assertSame('1000JPY', Amount::fromMinor(1000, 'JPY')->toMonetico());
        $this->assertSame('0JPY', Amount::zero('JPY')->toMonetico());
    }

    #[DataProvider('platformFormats')]
    public function test_it_reads_the_platform_format_back(string $raw, int $minor, string $currency): void
    {
        $amount = Amount::fromString($raw);

        $this->assertSame($minor, $amount->minor());
        $this->assertSame($currency, $amount->currency()->code());
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function platformFormats(): iterable
    {
        yield 'two decimals' => ['62.73EUR', 6273, 'EUR'];
        yield 'one decimal' => ['15.5EUR', 155, 'EUR'];
        yield 'no decimals' => ['100EUR', 100, 'EUR'];
        yield 'another currency' => ['95.25USD', 9525, 'USD'];
        yield 'zero' => ['0EUR', 0, 'EUR'];
        yield 'surrounded by spaces' => [' 10.00EUR ', 1000, 'EUR'];
        yield 'lower-case currency' => ['10.00eur', 1000, 'EUR'];
    }

    /**
     * The platform writes amounts with one, two or no decimals as it pleases,
     * and an amount read from a notification is written back unchanged.
     */
    public function test_reading_and_writing_an_amount_does_not_reformat_it(): void
    {
        $this->assertSame('15.5EUR', Amount::fromString('15.5EUR')->toMonetico());
        $this->assertSame('100EUR', Amount::fromString('100EUR')->toMonetico());
        $this->assertSame('62.73EUR', Amount::fromString('62.73EUR')->toMonetico());
    }

    public function test_the_same_money_written_two_ways_is_the_same_money(): void
    {
        // `15.5EUR` from a notification against `15.50EUR` from the database:
        // comparing the strings would reject a perfectly good payment.
        $this->assertTrue(Amount::fromString('15.5EUR')->equals(Amount::fromMinor(1550, 'EUR')));
        $this->assertTrue(Amount::fromString('100EUR')->equals(Amount::fromMinor(10000, 'EUR')));
        $this->assertFalse(Amount::fromString('15.5EUR')->equals(Amount::fromMinor(1551, 'EUR')));
    }

    public function test_the_same_number_in_another_currency_is_not_the_same_money(): void
    {
        $this->assertFalse(Amount::fromMinor(1000, 'EUR')->equals(Amount::fromMinor(1000, 'USD')));
    }

    public function test_it_adds_amounts_of_differing_precision(): void
    {
        $total = Amount::fromString('15.5EUR')->plus(Amount::fromString('4.25EUR'));

        $this->assertSame('19.75EUR', $total->toMonetico());
        $this->assertSame(1975, $total->minor());
    }

    public function test_it_refuses_to_add_two_currencies(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('one request carries one currency');

        Amount::fromMinor(100, 'EUR')->plus(Amount::fromMinor(100, 'USD'));
    }

    public function test_an_amount_is_never_negative(): void
    {
        // There is no field in this protocol that can carry a minus sign: a
        // refund is a different request, not a negative payment.
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('an amount cannot be negative');

        Amount::fromMinor(-1, 'EUR');
    }

    #[DataProvider('unreadableAmounts')]
    public function test_it_refuses_what_the_platform_would_refuse(string $raw): void
    {
        $this->expectException(InvalidField::class);

        Amount::fromString($raw);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableAmounts(): iterable
    {
        yield 'no currency' => ['62.73'];
        yield 'currency first' => ['EUR62.73'];
        yield 'three decimals' => ['62.735EUR'];
        yield 'a comma' => ['62,73EUR'];
        yield 'negative' => ['-62.73EUR'];
        yield 'with a space' => ['62.73 EUR'];
        yield 'empty' => [''];
        yield 'a float cast to string' => ['6.2730000000000001E+1EUR'];
    }

    public function test_it_knows_whether_it_is_zero(): void
    {
        $this->assertTrue(Amount::zero('EUR')->isZero());
        $this->assertFalse(Amount::fromMinor(1, 'EUR')->isZero());
    }

    public function test_it_takes_a_currency_object_or_its_code(): void
    {
        $this->assertTrue(
            Amount::fromMinor(100, Currency::euro())->equals(Amount::fromMinor(100, 'EUR')),
        );
    }

    public function test_it_is_its_own_field_value(): void
    {
        $this->assertSame('62.73EUR', (string) Amount::fromMinor(6273, 'EUR'));
    }

    /**
     * The reason this class stores integers: the arithmetic a shop does before
     * it gets here is the arithmetic that breaks.
     */
    public function test_a_total_built_from_cents_does_not_drift(): void
    {
        $total = Amount::zero('EUR');

        for ($line = 0; $line < 10; $line++) {
            $total = $total->plus(Amount::fromMinor(10, 'EUR'));
        }

        $this->assertSame('1.00EUR', $total->toMonetico());
        $this->assertSame(100, $total->minor());
    }
}
