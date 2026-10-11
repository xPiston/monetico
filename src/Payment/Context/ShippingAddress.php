<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment\Context;

/**
 * The `shipping` object: "mandatory if applicable", which is the
 * documentation's way of saying "if something is being shipped".
 *
 * `sameAsBilling()` is not a shortcut, it is a signal: `matchBillingAddress`
 * is one of the attributes a card issuer weighs when it decides whether to
 * challenge the customer, and an order delivered to the billing address is the
 * least suspicious thing in the catalogue.
 */
final class ShippingAddress extends ContextObject
{
    protected static function label(): string
    {
        return 'contexte_commande.shipping';
    }

    protected static function allowed(): array
    {
        return [
            'civility', 'name', 'firstName', 'lastName',
            'address', 'addressLine1', 'addressLine2', 'addressLine3',
            'city', 'postalCode', 'country', 'stateOrProvince', 'countrySubdivision',
            'email', 'phone',
            'shipIndicator', 'deliveryTimeframe', 'firstUseDate', 'matchBillingAddress',
        ];
    }

    public static function create(
        string $addressLine1,
        string $city,
        string $postalCode,
        string $country,
        ?string $stateOrProvince = null,
    ): self {
        return self::mandatory([
            'addressLine1' => $addressLine1,
            'city' => $city,
            'postalCode' => $postalCode,
            'country' => $country,
        ])->with(['stateOrProvince' => $stateOrProvince]);
    }

    /** Delivered to the address that is paying for it. */
    public static function sameAsBilling(BillingAddress $billing): self
    {
        $attributes = $billing->toArray();
        unset($attributes['mobilePhone'], $attributes['homePhone'], $attributes['workPhone']);

        /** @var array<string, string|int|float|bool|array<mixed>|null> $attributes */
        return (new self)->with($attributes)->with([
            'matchBillingAddress' => true,
            'shipIndicator' => ShipIndicator::BillingAddress->value,
        ]);
    }
}
