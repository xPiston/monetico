<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Payment;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Payment\Instalment;
use Piston\Monetico\Payment\Instalments;

final class InstalmentsTest extends TestCase
{
    public function test_it_builds_the_split_payment_fields(): void
    {
        $fields = $this->threeMonthly()->fields();

        $this->assertSame([
            'nbrech' => '3',
            'dateech1' => '05/05/2019',
            'montantech1' => '50.00EUR',
            'dateech2' => '05/06/2019',
            'montantech2' => '25.00EUR',
            'dateech3' => '05/07/2019',
            'montantech3' => '25.00EUR',
        ], $fields);
    }

    public function test_it_knows_its_total(): void
    {
        $this->assertSame('100.00EUR', $this->threeMonthly()->total()->toMonetico());
        $this->assertSame(3, $this->threeMonthly()->count());
    }

    public function test_the_dates_are_formatted_in_the_platform_timezone(): void
    {
        $instalments = Instalments::of(
            new Instalment(new DateTimeImmutable('2019-06-23 23:30:00', new DateTimeZone('UTC')), Amount::fromMinor(500, 'EUR')),
            new Instalment(new DateTimeImmutable('2019-07-23 23:30:00', new DateTimeZone('UTC')), Amount::fromMinor(500, 'EUR')),
        );

        $this->assertSame('24/06/2019', $instalments->fields()['dateech1']);
    }

    public function test_a_single_instalment_is_not_a_split_payment(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('between 2 and 4 instalments');

        Instalments::of(new Instalment(new DateTimeImmutable, Amount::fromMinor(100, 'EUR')));
    }

    public function test_five_instalments_are_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('between 2 and 4 instalments');

        Instalments::of(...array_map(
            static fn (int $month): Instalment => new Instalment(
                new DateTimeImmutable(sprintf('2019-%02d-05', $month)),
                Amount::fromMinor(100, 'EUR'),
            ),
            [1, 2, 3, 4, 5],
        ));
    }

    public function test_instalments_out_of_order_are_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('chronological order');

        Instalments::of(
            new Instalment(new DateTimeImmutable('2019-06-05'), Amount::fromMinor(100, 'EUR')),
            new Instalment(new DateTimeImmutable('2019-05-05'), Amount::fromMinor(100, 'EUR')),
        );
    }

    public function test_two_instalments_on_the_same_day_are_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('chronological order');

        Instalments::of(
            new Instalment(new DateTimeImmutable('2019-06-05 10:00'), Amount::fromMinor(100, 'EUR')),
            new Instalment(new DateTimeImmutable('2019-06-05 10:00'), Amount::fromMinor(100, 'EUR')),
        );
    }

    public function test_instalments_in_two_currencies_are_refused(): void
    {
        $this->expectException(InvalidField::class);
        $this->expectExceptionMessage('montantech2 is in USD but the order is in EUR');

        Instalments::of(
            new Instalment(new DateTimeImmutable('2019-05-05'), Amount::fromMinor(100, 'EUR')),
            new Instalment(new DateTimeImmutable('2019-06-05'), Amount::fromMinor(100, 'USD')),
        );
    }

    private function threeMonthly(): Instalments
    {
        $paris = new DateTimeZone('Europe/Paris');

        return Instalments::of(
            new Instalment(new DateTimeImmutable('2019-05-05', $paris), Amount::fromMinor(5000, 'EUR')),
            new Instalment(new DateTimeImmutable('2019-06-05', $paris), Amount::fromMinor(2500, 'EUR')),
            new Instalment(new DateTimeImmutable('2019-07-05', $paris), Amount::fromMinor(2500, 'EUR')),
        );
    }
}
