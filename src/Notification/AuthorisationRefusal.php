<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

/**
 * `motifrefusautorisation`: why the authorisation itself was refused.
 *
 * This is the field worth reading before showing anything to a customer,
 * because three of its values are instructions:
 *
 * - `Refus temporaire` says the same card may well work in a minute. Telling
 *   the customer their card was declined, there, is how a shop loses a sale it
 *   had.
 * - `Refus critique` says it never will. Offering a retry is cruel.
 * - `Refus repli VADS` says the bank wants the customer authenticated - the
 *   payment needs to go round again *with* 3D Secure, not be retried as is.
 */
enum AuthorisationRefusal: string
{
    use FromWire;

    case Bank = 'Refus banque';
    case Issuer = 'Refus emetteur';
    case Critical = 'Refus critique';
    case AuthenticationRequired = 'Refus repli VADS';
    case Temporary = 'Refus temporaire';
    case Technical = 'Refus technique';
    case Other = 'Refus autres';
    case TestSimulation = 'Refus test';

    /** Is it worth offering the same card again? */
    public function isRetryable(): bool
    {
        return $this === self::Temporary || $this === self::Technical;
    }

    /** Is this card finished, whatever the customer tries? */
    public function isFinal(): bool
    {
        return $this === self::Critical;
    }
}
