<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment;

/**
 * The full payment page, or the minimalist form meant for an iframe.
 *
 * The second needs the "iframe" option on the contract; without it the
 * platform refuses the form rather than falling back.
 */
enum DisplayMode: string
{
    case FullPage = 'full_page';
    case Iframe = 'iframe';
}
