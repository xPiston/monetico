<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

/**
 * `code-retour`: what happened to the payment.
 *
 * Two things are easy to get wrong here. The first is that an accepted
 * payment says `paiement` in production and `payetest` in the sandbox - so a
 * shop that only ever tested against the sandbox ships code that treats every
 * real payment as a failure, and the documentation is explicit that `payetest`
 * arriving in production is an anomaly in the other direction.
 *
 * The second is the instalments. `paiement_pf2` is a *successful* payment of
 * the second due date, weeks after the order; code that compares to
 * `'paiement'` with `===` books the first instalment and silently drops the
 * rest.
 */
enum ReturnCode: string
{
    use FromWire;

    case Accepted = 'paiement';
    case AcceptedInSandbox = 'payetest';
    case Refused = 'Annulation';
    case SecondInstalmentAccepted = 'paiement_pf2';
    case ThirdInstalmentAccepted = 'paiement_pf3';
    case FourthInstalmentAccepted = 'paiement_pf4';
    case SecondInstalmentRefused = 'Annulation_pf2';
    case ThirdInstalmentRefused = 'Annulation_pf3';
    case FourthInstalmentRefused = 'Annulation_pf4';

    public function isAccepted(): bool
    {
        return match ($this) {
            self::Accepted, self::AcceptedInSandbox,
            self::SecondInstalmentAccepted, self::ThirdInstalmentAccepted, self::FourthInstalmentAccepted => true,
            self::Refused,
            self::SecondInstalmentRefused, self::ThirdInstalmentRefused, self::FourthInstalmentRefused => false,
        };
    }

    /** Which instalment this is about, or null for a payment in one go. */
    public function instalment(): ?int
    {
        return match ($this) {
            self::SecondInstalmentAccepted, self::SecondInstalmentRefused => 2,
            self::ThirdInstalmentAccepted, self::ThirdInstalmentRefused => 3,
            self::FourthInstalmentAccepted, self::FourthInstalmentRefused => 4,
            default => null,
        };
    }

    /** True only for `payetest`, which exists in the sandbox and nowhere else. */
    public function isSandboxOnly(): bool
    {
        return $this === self::AcceptedInSandbox;
    }
}
