<?php

declare(strict_types=1);

namespace Piston\Monetico\Field;

use Piston\Monetico\Exception\InvalidField;
use Stringable;

/**
 * An amount, as an integer number of minor units and a currency.
 *
 * Never a float. `62.73` is not representable in binary floating point, so
 * `(string) (0.1 + 0.62)` is `0.72000000000000008` on the machine that builds
 * the form; a cent of drift between the seal and the amount is enough for the
 * platform to reject the payment, and a cent of drift between the capture and
 * the order is enough for it to answer "montant errone".
 *
 * The wire format is `[0-9]+(\.[0-9]{1,2})?[A-Z]{3}` - the currency glued to
 * the number, with no space and no sign.
 */
final class Amount implements Stringable
{
    private function __construct(
        private readonly int $minor,
        private readonly int $exponent,
        private readonly Currency $currency,
    ) {}

    /**
     * From the smallest unit of the currency: 6273 EUR cents, 62 JPY yen.
     *
     * This is the constructor to use from an application, because it is the
     * shape money has in a database that got it right.
     */
    public static function fromMinor(int $minor, Currency|string $currency): self
    {
        $currency = $currency instanceof Currency ? $currency : Currency::of($currency);

        if ($minor < 0) {
            throw InvalidField::because('montant', 'an amount cannot be negative', (string) $minor);
        }

        return new self($minor, $currency->decimals(), $currency);
    }

    /**
     * From the platform's own format, as it arrives in a notification.
     *
     * Deliberately liberal: it keeps the number of decimals it was given
     * rather than the number the currency should have, so an amount read from
     * a notification and written back out is the same string. A notification
     * must never be refused over a formatting disagreement - the seal is what
     * decides whether to trust it.
     */
    public static function fromString(string $raw): self
    {
        $raw = trim($raw);

        if (preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?([A-Za-z]{3})$/', $raw, $matches) !== 1) {
            throw InvalidField::because('montant', 'expected an amount such as 62.73EUR', $raw);
        }

        $currency = Currency::of($matches[3]);
        $fraction = $matches[2];
        $exponent = strlen($fraction);
        $minor = (int) $matches[1] * 10 ** $exponent + (int) ($fraction === '' ? '0' : $fraction);

        return new self($minor, $exponent, $currency);
    }

    public static function zero(Currency|string $currency): self
    {
        return self::fromMinor(0, $currency);
    }

    /** The field value: `62.73EUR`, or `0EUR` for a currency with no decimals. */
    public function toMonetico(): string
    {
        if ($this->exponent === 0) {
            return $this->minor.$this->currency->code();
        }

        $divisor = 10 ** $this->exponent;
        $units = intdiv($this->minor, $divisor);
        $fraction = $this->minor % $divisor;

        return sprintf('%d.%0'.$this->exponent.'d%s', $units, $fraction, $this->currency->code());
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        [$left, $right, $exponent] = $this->align($other);

        return new self($left + $right, $exponent, $this->currency);
    }

    public function equals(self $other): bool
    {
        if (! $this->currency->equals($other->currency)) {
            return false;
        }

        [$left, $right] = $this->align($other);

        return $left === $right;
    }

    public function isSameCurrencyAs(self $other): bool
    {
        return $this->currency->equals($other->currency);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function align(self $other): array
    {
        $exponent = max($this->exponent, $other->exponent);

        return [
            $this->minor * 10 ** ($exponent - $this->exponent),
            $other->minor * 10 ** ($exponent - $other->exponent),
            $exponent,
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw InvalidField::mixedCurrencies('montant', $this->currency->code(), $other->currency->code());
        }
    }

    public function __toString(): string
    {
        return $this->toMonetico();
    }
}
