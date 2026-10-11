<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment\Context;

/** How the shop authenticated the customer before the payment. */
enum AuthenticationMethod: string
{
    case Guest = 'guest';
    case OwnCredentials = 'own_credentials';
    case FederatedId = 'federated_id';
    case IssuerCredentials = 'issuer_credentials';
    case ThirdPartyAuthentication = 'third_party_authentication';
    case Fido = 'fido';
}
