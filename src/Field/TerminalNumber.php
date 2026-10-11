<?php

declare(strict_types=1);

namespace Piston\Monetico\Field;

use Piston\Monetico\Exception\InvalidField;
use Stringable;

/**
 * The virtual point-of-sale number, `TPE` on the wire.
 *
 * Seven alphanumeric characters, and the first field of every message in both
 * directions. A notification for another terminal is not a notification for
 * this shop, which is why Monetico::notification() compares it rather than
 * trusting the seal alone.
 */
final class TerminalNumber implements Stringable
{
    private function __construct(private readonly string $value) {}

    public static function of(string $value): self
    {
        $value = trim($value);

        if (preg_match('/^[A-Za-z0-9]{7}$/', $value) !== 1) {
            throw InvalidField::because('TPE', 'expected 7 alphanumeric characters', $value);
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
