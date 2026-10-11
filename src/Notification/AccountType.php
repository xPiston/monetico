<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

/** `typecompte`: whose account the card is attached to. */
enum AccountType: string
{
    use FromWire;

    case Personal = 'particulier';
    case Business = 'commercial';
    case Unknown = 'inconnu';
}
