<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

/**
 * `brand`: the card network.
 *
 * `na` is not an error - the documentation says it is returned systematically
 * in the test environment, so any code that treats an unknown brand as a
 * failure fails every sandbox payment. `CO` is Conecs, the French meal-voucher
 * scheme, and it brings two fields of its own: the issuer and the remaining
 * balance on the account.
 */
enum CardBrand: string
{
    use FromWire;

    case AmericanExpress = 'AM';
    case CarteBancaire = 'CB';
    case Mastercard = 'MC';
    case Visa = 'VI';
    case Conecs = 'CO';
    case NotAvailable = 'na';

    public function label(): string
    {
        return match ($this) {
            self::AmericanExpress => 'American Express',
            self::CarteBancaire => 'Carte Bancaire',
            self::Mastercard => 'Mastercard',
            self::Visa => 'Visa',
            self::Conecs => 'Conecs',
            self::NotAvailable => 'Not available',
        };
    }

    /** A meal voucher, which is not a bank card and does not behave like one. */
    public function isMealVoucher(): bool
    {
        return $this === self::Conecs;
    }
}
