<?php

declare(strict_types=1);

namespace Piston\Monetico\Field;

use Piston\Monetico\Exception\InvalidField;
use Stringable;

/**
 * `societe`: the code that lets one terminal serve several sites with
 * their own settings - their own return URLs, their own payment page.
 *
 * Alphanumeric, generated when the contract is created. Getting it wrong does
 * not produce a seal error but a "your merchant's site was not identified"
 * page, which is a different wild goose chase.
 */
final class CompanyCode implements Stringable
{
    private function __construct(private readonly string $value) {}

    public static function of(string $value): self
    {
        $value = trim($value);

        if (preg_match('/^[A-Za-z0-9]{1,64}$/', $value) !== 1) {
            throw InvalidField::because('societe', 'expected 1 to 64 alphanumeric characters', $value);
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
