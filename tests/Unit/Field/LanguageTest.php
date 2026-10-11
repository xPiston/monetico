<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Field\Language;

final class LanguageTest extends TestCase
{
    #[DataProvider('locales')]
    public function test_it_reads_a_locale_in_whatever_shape_an_application_keeps_it(string $locale, Language $expected): void
    {
        $this->assertSame($expected, Language::fromLocale($locale));
    }

    /** @return iterable<string, array{string, Language}> */
    public static function locales(): iterable
    {
        yield 'two letters' => ['fr', Language::French];
        yield 'upper case' => ['FR', Language::French];
        yield 'with an underscore' => ['fr_FR', Language::French];
        yield 'with a dash' => ['fr-BE', Language::French];
        yield 'another country' => ['en_GB', Language::English];
        yield 'with surrounding space' => [' es ', Language::Spanish];
        yield 'portuguese' => ['pt_BR', Language::Portuguese];
        yield 'japanese' => ['ja', Language::Japanese];
    }

    public function test_a_language_the_payment_page_does_not_speak_falls_back(): void
    {
        // The page has to be in some language, and a French bank's customers
        // are the safe guess.
        $this->assertSame(Language::French, Language::fromLocale('pl'));
        $this->assertSame(Language::French, Language::fromLocale(''));
        $this->assertSame(Language::English, Language::fromLocale('pl', Language::English));
    }

    public function test_the_wire_values_are_the_two_letter_codes(): void
    {
        $this->assertSame('FR', Language::French->value);
        $this->assertSame('SV', Language::Swedish->value);
        $this->assertCount(9, Language::cases());
    }
}
