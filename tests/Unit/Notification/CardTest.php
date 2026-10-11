<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Notification\Card;
use Piston\Monetico\Notification\CardBrand;

final class CardTest extends TestCase
{
    public function test_an_empty_payload_gives_a_card_that_knows_nothing(): void
    {
        $card = Card::fromFields([]);

        $this->assertNull($card->brand);
        $this->assertNull($card->maskedNumber);
        $this->assertNull($card->cryptogramEntered);
        $this->assertNull($card->expiresAt());
    }

    public function test_it_reads_the_expiry_as_the_last_moment_of_that_month(): void
    {
        $card = Card::fromFields(['vld' => '1019']);

        $this->assertSame('2019-10-31 23:59:59', $card->expiresAt()?->format('Y-m-d H:i:s'));
    }

    /**
     * The `!` in the date format earns its keep here: without it PHP fills the
     * day from today, and asking for the expiry of a February card on the 31st
     * of a month lands in March.
     */
    public function test_the_expiry_does_not_depend_on_what_day_it_is_today(): void
    {
        $card = Card::fromFields(['vld' => '0219']);

        $this->assertSame('2019-02-28 23:59:59', $card->expiresAt()?->format('Y-m-d H:i:s'));
    }

    public function test_a_leap_year_expiry_ends_on_the_twenty_ninth(): void
    {
        $this->assertSame(
            '2020-02-29 23:59:59',
            Card::fromFields(['vld' => '0220'])->expiresAt()?->format('Y-m-d H:i:s'),
        );
    }

    #[DataProvider('unreadableExpiries')]
    public function test_an_expiry_it_cannot_read_is_null(string $vld): void
    {
        $this->assertNull(Card::fromFields(['vld' => $vld])->expiresAt());
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableExpiries(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['109'];
        yield 'too long' => ['102019'];
        yield 'letters' => ['abcd'];
        yield 'with a slash' => ['10/19'];
    }

    #[DataProvider('yesNoFields')]
    public function test_it_reads_the_yes_and_no_fields(string $value, bool $expected): void
    {
        $this->assertSame($expected, Card::fromFields(['cvx' => $value])->cryptogramEntered);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function yesNoFields(): iterable
    {
        yield 'oui' => ['oui', true];
        yield 'OUI' => ['OUI', true];
        yield 'yes, from the English edition' => ['yes', true];
        yield 'non' => ['non', false];
        yield 'anything else' => ['peut-etre', false];
    }

    public function test_the_card_saved_flag_is_a_digit_and_not_a_word(): void
    {
        $this->assertTrue(Card::fromFields(['cbenregistree' => '1'])->savedUnderAlias);
        $this->assertFalse(Card::fromFields(['cbenregistree' => '0'])->savedUnderAlias);
    }

    /**
     * `na` is returned systematically in the test environment, so treating an
     * unknown brand as a failure would fail every sandbox payment.
     */
    public function test_the_sandbox_brand_is_a_brand(): void
    {
        $this->assertSame(CardBrand::NotAvailable, Card::fromFields(['brand' => 'na'])->brand);
    }

    public function test_an_unknown_brand_is_null_and_not_an_exception(): void
    {
        $this->assertNull(Card::fromFields(['brand' => 'ZZ'])->brand);
    }

    public function test_every_documented_brand_is_known(): void
    {
        foreach (['AM', 'CB', 'MC', 'VI', 'CO', 'na'] as $wire) {
            $brand = Card::fromFields(['brand' => $wire])->brand;

            $this->assertInstanceOf(CardBrand::class, $brand);
            $this->assertNotSame('', $brand->label());
        }
    }

    public function test_only_the_meal_voucher_scheme_is_a_meal_voucher(): void
    {
        $this->assertTrue(CardBrand::Conecs->isMealVoucher());
        $this->assertFalse(CardBrand::Visa->isMealVoucher());
    }

    public function test_a_card_can_be_built_directly(): void
    {
        $card = new Card(brand: CardBrand::Visa, maskedNumber: '12345678******12');

        $this->assertSame(CardBrand::Visa, $card->brand);
        $this->assertSame('12345678******12', $card->maskedNumber);
    }
}
