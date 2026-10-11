<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment\Context;

/**
 * The `client` object: who is paying, and what the shop knows about them.
 *
 * Every attribute is optional and every attribute is worth sending. This is
 * the part of the context that answers "has this person had an account here
 * for three years and bought eleven times" - the answer that gets a payment
 * through without a challenge. Nothing here is required to take money; it is
 * required to stop losing customers at the 3D Secure step.
 */
final class ClientInformation extends ContextObject
{
    protected static function label(): string
    {
        return 'contexte_commande.client';
    }

    protected static function allowed(): array
    {
        return [
            'civility', 'name', 'firstName', 'lastName', 'middleName',
            'address', 'addressLine1', 'addressLine2', 'addressLine3',
            'city', 'postalCode', 'country', 'stateOrProvince', 'countrySubdivision',
            'email', 'phone', 'mobilePhone', 'homePhone', 'workPhone',
            'birthLastName', 'birthCity', 'birthPostalCode', 'birthCountry',
            'birthStateOrProvince', 'birthCountrySubdivision', 'birthdate',
            'nationalIDNumber', 'suspiciousAccountActivity',
            'authenticationMethod', 'authenticationTimestamp',
            'priorAuthenticationMethod', 'priorAuthenticationTimestamp',
            'paymentMeanAge', 'accountAge', 'lastAccountModification', 'lastPasswordChange',
            'lastYearTransactions', 'last24HoursTransactions', 'last6MonthsPurchase',
            'addCardNbLast24Hours',
        ];
    }

    public static function create(?string $email = null): self
    {
        return (new self)->with(['email' => $email]);
    }

    /** Nobody signed in: the weakest signal there is, and an honest one. */
    public static function guest(?string $email = null): self
    {
        return self::create($email)->with(['authenticationMethod' => AuthenticationMethod::Guest->value]);
    }
}
