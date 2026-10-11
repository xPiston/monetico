<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment\Context;

/**
 * The `billing` object, the one part of the order context the platform calls
 * mandatory outright.
 *
 * Four attributes are required with it: the first address line, the city, the
 * postal code and the country. `stateOrProvince` is "mandatory if applicable",
 * which means the United States, Canada and anywhere else whose postal
 * addresses carry one - so it is a named argument here rather than buried in
 * `with()`.
 */
final class BillingAddress extends ContextObject
{
    protected static function label(): string
    {
        return 'contexte_commande.billing';
    }

    protected static function allowed(): array
    {
        return [
            'civility', 'name', 'firstName', 'lastName', 'middleName',
            'address', 'addressLine1', 'addressLine2', 'addressLine3',
            'city', 'postalCode', 'country', 'stateOrProvince', 'countrySubdivision',
            'email', 'phone', 'mobilePhone', 'homePhone', 'workPhone',
        ];
    }

    /**
     * @param  string  $country  ISO 3166-1 alpha-2, such as FR
     */
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
}
