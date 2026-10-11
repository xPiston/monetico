<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment\Context;

/**
 * Where the order is going, as 3D Secure describes it.
 *
 * Worth a type because the values are an enumeration the issuer scores
 * against, and `"digital"` instead of `"digital_goods"` is not a validation
 * error - it is a slightly higher chance of a challenge, for the lifetime of
 * the integration.
 */
enum ShipIndicator: string
{
    case BillingAddress = 'billing_address';
    case AnotherVerifiedAddress = 'another_verified_address';
    case DifferentAddress = 'different_address';
    case ShipToStore = 'ship_to_store';
    case DigitalGoods = 'digital_goods';
    case TravelAndEventTickets = 'travel_and_event_tickets';
    case Other = 'other';
}
