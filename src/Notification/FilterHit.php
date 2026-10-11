<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

/**
 * One rule of the shop's own fraud prevention module that stopped a payment.
 *
 * The three fields behind this - `filtragecause`, `filtragevaleur` and
 * `filtrage_etat` - are parallel lists joined by hyphens, "the causes and
 * corresponding values in the same order". So they are read together, once,
 * rather than three times by three pieces of application code.
 *
 * `informationOnly` is the one to check before telling anybody anything: a
 * filter in information mode did *not* block the payment. It reports what it
 * would have done, which is how a shop tries out a rule before enforcing it.
 */
final class FilterHit
{
    public function __construct(
        public readonly int $cause,
        public readonly string $value,
        public readonly bool $informationOnly,
    ) {}

    /**
     * @param  array<string, string>  $fields
     * @return list<self>
     */
    public static function fromFields(array $fields): array
    {
        $causes = self::split($fields['filtragecause'] ?? '');

        if ($causes === []) {
            return [];
        }

        $values = self::split($fields['filtragevaleur'] ?? '');
        $states = self::split($fields['filtrage_etat'] ?? '');

        $hits = [];

        foreach ($causes as $position => $cause) {
            if (preg_match('/^\d+$/', $cause) !== 1) {
                continue;
            }

            $hits[] = new self(
                cause: (int) $cause,
                value: $values[$position] ?? '',
                informationOnly: strtolower($states[$position] ?? '') === 'information',
            );
        }

        return $hits;
    }

    public function label(): string
    {
        return match ($this->cause) {
            1 => 'IP address',
            2 => 'Card number',
            3 => 'Card BIN',
            4 => 'Country of the card',
            5 => 'Country of the IP',
            6 => 'Consistency between the country of the card and of the IP',
            7 => 'Disposable email',
            8 => 'Amount per card over a period',
            9 => 'Number of transactions per card over a period',
            11 => 'Number of transactions per alias over a period',
            12 => 'Amount per alias over a period',
            13 => 'Amount per IP over a period',
            14 => 'Number of transactions per IP over a period',
            15 => 'Card testers',
            16 => 'Number of aliases per card',
            default => 'Unknown filter '.$this->cause,
        };
    }

    /** @return list<string> */
    private static function split(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        // The platform ends these lists with a trailing hyphen, so the last
        // element is empty and is not a filter.
        return array_values(array_filter(
            explode('-', $raw),
            static fn (string $part, int $index): bool => $part !== '' || $index === 0,
            ARRAY_FILTER_USE_BOTH,
        ));
    }
}
