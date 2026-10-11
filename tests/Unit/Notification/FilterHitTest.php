<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Notification;

use PHPUnit\Framework\TestCase;
use Piston\Monetico\Notification\FilterHit;

final class FilterHitTest extends TestCase
{
    public function test_no_filter_fired(): void
    {
        $this->assertSame([], FilterHit::fromFields([]));
        $this->assertSame([], FilterHit::fromFields(['filtragecause' => '']));
    }

    /** The shape the documentation's own example has, trailing hyphen included. */
    public function test_one_filter_with_the_trailing_hyphen_the_platform_sends(): void
    {
        $hits = FilterHit::fromFields(['filtragecause' => '4-', 'filtragevaleur' => 'FRA-']);

        $this->assertCount(1, $hits);
        $this->assertSame(4, $hits[0]->cause);
        $this->assertSame('FRA', $hits[0]->value);
        $this->assertSame('Country of the card', $hits[0]->label());
    }

    public function test_several_filters_keep_their_values_in_the_same_order(): void
    {
        $hits = FilterHit::fromFields([
            'filtragecause' => '1-4-7-',
            'filtragevaleur' => '10.45.166.76-FRA-jetable.test-',
        ]);

        $this->assertCount(3, $hits);
        $this->assertSame([1, 4, 7], array_map(static fn (FilterHit $h): int => $h->cause, $hits));
        $this->assertSame('10.45.166.76', $hits[0]->value);
        $this->assertSame('FRA', $hits[1]->value);
        $this->assertSame('jetable.test', $hits[2]->value);
    }

    /**
     * A filter in information mode did not block anything. It reports what it
     * would have done, which is how a shop tries a rule out before enforcing
     * it - and why "a filter fired" is not the same as "the payment was
     * blocked".
     */
    public function test_a_filter_in_information_mode_blocked_nothing(): void
    {
        $hits = FilterHit::fromFields([
            'filtragecause' => '4-5-',
            'filtragevaleur' => 'FRA-DEU-',
            'filtrage_etat' => 'information--',
        ]);

        $this->assertTrue($hits[0]->informationOnly);
        $this->assertFalse($hits[1]->informationOnly);
    }

    public function test_a_filter_with_no_value_still_counts(): void
    {
        $hits = FilterHit::fromFields(['filtragecause' => '15-']);

        $this->assertCount(1, $hits);
        $this->assertSame('', $hits[0]->value);
        $this->assertSame('Card testers', $hits[0]->label());
    }

    public function test_a_cause_that_is_not_a_number_is_ignored(): void
    {
        $this->assertSame([], FilterHit::fromFields(['filtragecause' => 'abc-']));
    }

    public function test_a_filter_number_this_library_does_not_know_still_reports(): void
    {
        $hits = FilterHit::fromFields(['filtragecause' => '99-', 'filtragevaleur' => 'x-']);

        $this->assertCount(1, $hits);
        $this->assertSame('Unknown filter 99', $hits[0]->label());
    }

    public function test_every_documented_filter_has_a_label(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 12, 13, 14, 15, 16] as $cause) {
            $hits = FilterHit::fromFields(['filtragecause' => $cause.'-']);

            $this->assertStringNotContainsString('Unknown', $hits[0]->label());
        }
    }
}
