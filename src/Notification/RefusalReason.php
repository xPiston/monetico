<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

/**
 * `motifrefus`: why the payment request was turned down.
 *
 * The distinction that matters to a shop is between a bank saying no and the
 * shop's own fraud module saying no: `filtrage` and `scoring` are the
 * merchant's own rules firing, and they are the two a support agent can
 * actually do something about.
 */
enum RefusalReason: string
{
    use FromWire;

    case PhoneCall = 'Appel Phonie';
    case Refused = 'Refus';
    case Forbidden = 'Interdit';
    case BlockedByFilter = 'filtrage';
    case BlockedByScoring = 'scoring';
    case ThreeDSecure = '3DSecure';

    /** The shop's own fraud prevention module, rather than a bank. */
    public function isMerchantsOwnRule(): bool
    {
        return $this === self::BlockedByFilter || $this === self::BlockedByScoring;
    }
}
