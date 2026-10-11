<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment\Context;

use JsonSerializable;
use Piston\Monetico\Exception\InvalidField;

/**
 * One object of the `contexte_commande` document.
 *
 * The four of them - billing, shipping, shoppingCart, client - carry about
 * seventy attributes between them, almost all optional, and they exist for 3D
 * Secure: the more of them the shop sends, the more often the customer's bank
 * lets a payment through without a challenge. Which is the whole economic
 * argument for filling them in.
 *
 * Seventy constructor parameters would be a worse library than seventy
 * untyped array keys, so the deal here is: the handful of attributes the
 * platform calls mandatory are typed parameters on the named constructors,
 * and the long tail goes through `with()`, which checks every name against
 * the documented list. A typo is still an exception before anything is sent -
 * it is just not a typo in a method signature.
 *
 * Nulls and empty strings are dropped rather than sent: the documentation
 * forbids sending an empty string for an absent value, and an application
 * passing `$customer->phone ?? ''` is describing an absent value.
 */
abstract class ContextObject implements JsonSerializable
{
    /** @var array<string, string|int|float|bool|array<mixed>> */
    protected array $attributes = [];

    /**
     * The attribute names the platform documents for this object.
     *
     * @return list<string>
     */
    abstract protected static function allowed(): array;

    /** The object's name in the JSON document, for error messages. */
    abstract protected static function label(): string;

    /**
     * @param  array<string, string|int|float|bool|array<mixed>|null>  $attributes
     */
    final public function with(array $attributes): static
    {
        $clone = clone $this;

        foreach ($attributes as $name => $value) {
            if (! in_array($name, static::allowed(), true)) {
                throw InvalidField::because(
                    static::label(),
                    sprintf('"%s" is not an attribute of this object (%s)', $name, implode(', ', static::allowed())),
                    $name,
                );
            }

            if ($value === null || $value === '' || $value === []) {
                unset($clone->attributes[$name]);

                continue;
            }

            $clone->attributes[$name] = $value;
        }

        return $clone;
    }

    final public function has(string $attribute): bool
    {
        return array_key_exists($attribute, $this->attributes);
    }

    /** @return array<string, string|int|float|bool|array<mixed>> */
    final public function toArray(): array
    {
        return $this->attributes;
    }

    /** @return array<string, string|int|float|bool|array<mixed>> */
    final public function jsonSerialize(): array
    {
        return $this->attributes;
    }

    /**
     * @param  array<string, string|int|float|bool|array<mixed>|null>  $attributes
     */
    final protected static function mandatory(array $attributes): static
    {
        foreach ($attributes as $name => $value) {
            if ($value === null || $value === '') {
                throw InvalidField::because(static::label(), sprintf('"%s" is required', $name), (string) $value);
            }
        }

        // @phpstan-ignore-next-line new static() on an abstract class is safe here: every subclass is concrete and parameterless.
        return (new static)->with($attributes);
    }
}
