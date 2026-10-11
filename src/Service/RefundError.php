<?php

declare(strict_types=1);

namespace Piston\Monetico\Service;

/**
 * Why a refund did not happen, from the `cdr` of its reply.
 *
 * The refund service numbers its failures, which makes them the one part of
 * this protocol that can be matched on safely - and there are enough of them
 * that "the refund failed" is rarely a useful thing to log. Three are worth
 * singling out:
 *
 * - `AlreadyFullyRefunded` is a job that has already done its work. Treating
 *   it as a failure and retrying is how a queue spends a night on it.
 * - `NotAuthorised` and `TooManyRefunds` are contract limits, not bugs: the
 *   refund service has to be enabled, and each calling IP address has a daily
 *   quota the merchant agrees with support.
 * - `AuthorisationAndRemittanceTogether` and `GlobalRefundNotAllowed` are the
 *   two halves of the same trap: a refund may omit the authorisation number
 *   and the remittance date, but only together, and only when the order
 *   allows a whole-order refund.
 */
enum RefundError: int
{
    case Refused = -1;
    case MerchantNotIdentified = -30;
    case InvalidSeal = -31;
    case NotAuthorised = -32;
    case RequestExpired = -33;
    case WrongRefundAmount = -34;
    case AmountsOutOfStep = -35;
    case TooManyRefunds = -36;
    case OrderNotFound = -37;
    case OrderNotPaid = -38;
    case PaymentNotFound = -39;
    case OverThreshold = -40;
    case TechnicalProblem = -41;
    case WrongCurrency = -42;
    case InvalidParameters = -43;
    case AnotherInProgress = -44;
    case CardCheckFailed = -45;
    case AlreadyFullyRefunded = -46;
    case SeveralPaymentsFound = -47;
    case PartiallyRefunded = -48;
    case AmexDisabled = -49;
    case AuthorisationAndRemittanceTogether = -50;
    case GlobalRefundNotAllowed = -51;
    case WrongAlreadyRefundedAmount = -52;

    public function isRetryable(): bool
    {
        return match ($this) {
            self::TechnicalProblem, self::AnotherInProgress => true,
            default => false,
        };
    }

    /** Nothing to do: the money is already back with the customer. */
    public function isAlreadyDone(): bool
    {
        return $this === self::AlreadyFullyRefunded;
    }
}
