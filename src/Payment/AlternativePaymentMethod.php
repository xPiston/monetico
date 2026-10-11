<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment;

/**
 * The payment methods that can appear next to the card form, and so the ones
 * that can be switched off for a given order.
 *
 * Useful for more than cosmetics: paying in three instalments through a
 * partner is a credit agreement, and a shop may be obliged to hide it for
 * some baskets.
 */
enum AlternativePaymentMethod: string
{
    case OneEuro = '1euro';
    case ThreeTimesCard = '3xcb';
    case FourTimesCard = '4xcb';
    case PayPal = 'paypal';
    case LyfPay = 'lyfpay';
}
