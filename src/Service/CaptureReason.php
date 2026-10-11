<?php

declare(strict_types=1);

namespace Piston\Monetico\Service;

/**
 * The `lib` of a capture reply, as documented.
 *
 * Read it for the detail, never for the decision: `lib` is prose, it is
 * localised by the `lgue` field - the platform's own troubleshooting section
 * quotes `signature not valid` where its reference table says
 * `signature non valide` - and it is the kind of string that gets a typo fixed
 * in a release note. `cdr` is the contract; this is the explanation, and both
 * spellings are accepted for the ones the documentation gives twice.
 */
enum CaptureReason: string
{
    case PaymentAccepted = 'paiement accepte';
    case OrderCancelled = 'commande annulee';
    case RecurrenceStopped = 'recurrence stoppee';
    case OrderNotAuthenticated = 'commande non authentifiee';
    case OrderExpired = 'commande expiree';
    case TooManyCardAttempts = 'commande grillee';
    case AuthorisationRefused = 'autorisation refusee';
    case CancellationRefused = 'annulation refusee';
    case AlreadyCancelled = 'la commande est deja annulee';
    case AlreadyAccepted = 'paiement deja accepte';
    case InvalidSeal = 'signature non valide';
    case WrongPaymentMode = 'verification echouee (mode de paiement)';
    case Malformed = 'la demande ne peut aboutir';
    case WrongAmount = 'montant errone';
    case MerchantNotIdentified = 'commercant non identifie';
    case InProgress = 'traitement en cours';
    case WrongDate = 'date erronee';
    case AnotherInProgress = 'autre traitement en cours';
    case ServiceUnavailable = 'indisponibilite temporaire du service';
    case TechnicalProblem = 'probleme technique';

    /** @var array<string, string> */
    private const ALSO_KNOWN_AS = [
        'signature not valid' => 'signature non valide',
        'merchant not identified' => 'commercant non identifie',
    ];

    public static function tryFromWire(string $value): ?self
    {
        $value = strtolower(trim($value));
        $value = self::ALSO_KNOWN_AS[$value] ?? $value;

        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Is sending the same request again a reasonable thing to do?
     *
     * The documentation says so for these four, and says nothing for the
     * others - which is the difference between a job that should be retried
     * and a job that should stop and be looked at.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::InProgress, self::AnotherInProgress, self::ServiceUnavailable, self::TechnicalProblem => true,
            default => false,
        };
    }
}
