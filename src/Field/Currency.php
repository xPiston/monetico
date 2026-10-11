<?php

declare(strict_types=1);

namespace Piston\Monetico\Field;

use Piston\Monetico\Exception\InvalidField;
use Stringable;

/**
 * An ISO 4217 code, and the one thing the amount format needs to know about
 * it: how many decimals it has.
 *
 * Monetico accepts at most two, which is a constraint on the shop and not on
 * this library: a price in Kuwaiti dinars has three, and there is no way to
 * express 1.234 KWD in a field whose own regular expression stops at
 * hundredths. Rather than silently round somebody's money, the currencies that
 * cannot be carried are refused by name.
 */
final class Currency implements Stringable
{
    /**
     * Currencies with no minor unit: 100 of them is 100, not 1.00.
     *
     * @var list<string>
     */
    private const WITHOUT_DECIMALS = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW',
        'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** Currencies whose minor unit is a thousandth, which the format cannot carry. */
    private const WITH_THREE_DECIMALS = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    private function __construct(private readonly string $code) {}

    public static function of(string $code): self
    {
        $code = strtoupper(trim($code));

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw InvalidField::because('currency', 'expected three letters as per ISO 4217', $code);
        }

        if (in_array($code, self::WITH_THREE_DECIMALS, true)) {
            throw InvalidField::because(
                'currency',
                'Monetico amounts carry at most two decimals, so a currency with three cannot be expressed',
                $code,
            );
        }

        return new self($code);
    }

    public static function euro(): self
    {
        return new self('EUR');
    }

    public function code(): string
    {
        return $this->code;
    }

    public function decimals(): int
    {
        return in_array($this->code, self::WITHOUT_DECIMALS, true) ? 0 : 2;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
