<?php

declare(strict_types=1);

namespace Piston\Monetico\Field;

use Piston\Monetico\Exception\InvalidField;
use Stringable;

/**
 * The order reference - the one value that ties a payment, its notification,
 * its capture and its refund together.
 *
 * It has two sets of rules, and they do not agree. The payment form accepts
 * any printable ASCII up to 50 characters; the capture and refund services
 * accept letters and digits only. A reference such as `CMD-2026-0042` is
 * therefore payable and not capturable, and the way you find out is a
 * `commande non authentifiee` on the day you try to collect it.
 *
 * So this validates the wider rule and can answer the narrower one, and the
 * capture and refund requests ask before they send.
 */
final class Reference implements Stringable
{
    private function __construct(private readonly string $value) {}

    public static function of(string $value): self
    {
        if (preg_match('/^[\x20-\x7E]{1,50}$/', $value) !== 1) {
            throw InvalidField::because(
                'reference',
                'expected 1 to 50 printable ASCII characters, with no line break',
                $value,
            );
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    /** Can this reference be used with the capture and refund services? */
    public function isServiceSafe(): bool
    {
        return preg_match('/^[A-Za-z0-9]{1,50}$/', $this->value) === 1;
    }

    /**
     * Will the bank carry it through to the merchant's statement?
     *
     * The documentation advises at most 12 alphanumeric characters for the
     * reference to survive into the remote collection details - which is what
     * makes a payment traceable from a bank statement back to an order.
     */
    public function fitsOnBankStatement(): bool
    {
        return preg_match('/^[A-Za-z0-9]{1,12}$/', $this->value) === 1;
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
