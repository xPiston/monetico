<?php

declare(strict_types=1);

namespace Piston\Monetico\Exception;

use InvalidArgumentException;

/**
 * A value does not match what the payment platform accepts.
 *
 * Every message is thrown *before* anything is sent, and names the field and
 * the rule, because the alternative is the platform's own answer: a page
 * saying "the information transmitted by your merchant has an invalid
 * signature", which says nothing about which field was wrong.
 *
 * The value is included on purpose - these are order references, amounts and
 * dates, not secrets. The one exception is the security key (see below), whose
 * message never contains it.
 */
final class InvalidField extends InvalidArgumentException implements MoneticoException
{
    public static function because(string $field, string $rule, string $given): self
    {
        return new self(sprintf('%s: %s, got "%s".', $field, $rule, $given));
    }

    public static function securityKey(string $rule): self
    {
        // Deliberately without the value: this one ends up in logs.
        return new self(sprintf('Security key: %s.', $rule));
    }

    public static function amountsDoNotAddUp(string $expected, string $sum): self
    {
        return new self(sprintf(
            'A capture must account for the whole order: montant_a_capturer + montant_deja_capture + montant_restant = %s, but the order is %s. '
            .'The platform answers "montant errone" to the version of this request that reaches it.',
            $sum,
            $expected,
        ));
    }

    public static function mixedCurrencies(string $field, string $expected, string $given): self
    {
        return new self(sprintf('%s is in %s but the order is in %s: one request carries one currency.', $field, $given, $expected));
    }
}
