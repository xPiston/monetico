<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

/** `modepaiement`: how the customer actually paid. */
enum PaymentMethod: string
{
    use FromWire;

    case Card = 'CB';
    case PayPal = 'paypal';
    case OneEuro = '1euro';
    case ThreeTimesCard = '3xcb';
    case FourTimesCard = '4xcb';
    case Audiotel = 'audiotel';
}
