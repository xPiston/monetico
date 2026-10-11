<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

/** `usage`: what kind of card paid. */
enum CardUsage: string
{
    use FromWire;

    case Credit = 'credit';
    case Debit = 'debit';
    case Prepaid = 'prepaye';
    case Unknown = 'inconnu';
}
