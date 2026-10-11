<?php

declare(strict_types=1);

namespace Piston\Monetico\Field;

/**
 * `lgue`: the language of the payment page.
 *
 * An enum rather than a string because the list is closed and short, and
 * because a language the platform does not know is not a translation problem
 * but a rejected form.
 */
enum Language: string
{
    case German = 'DE';
    case English = 'EN';
    case Spanish = 'ES';
    case French = 'FR';
    case Italian = 'IT';
    case Japanese = 'JA';
    case Dutch = 'NL';
    case Portuguese = 'PT';
    case Swedish = 'SV';

    /**
     * The closest supported language to a locale, or French.
     *
     * Takes `fr`, `fr_FR`, `fr-BE` and `FR` alike, because this is fed from an
     * application's own locale and those are the four shapes it comes in. The
     * fallback is a choice, not an accident: the payment page has to be in
     * *some* language, and a French bank's customers are the safe guess.
     */
    public static function fromLocale(string $locale, self $fallback = self::French): self
    {
        $primary = strtoupper(substr(str_replace('_', '-', trim($locale)), 0, 2));

        return self::tryFrom($primary) ?? $fallback;
    }
}
