<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment\Context;

/** How fast the order is going out, as 3D Secure describes it. */
enum DeliveryTimeframe: string
{
    case ElectronicDelivery = 'electronic_delivery';
    case SameDay = 'same_day';
    case Overnight = 'overnight';
    case TwoDay = 'two_day';
}
