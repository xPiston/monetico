<?php

declare(strict_types=1);

namespace Piston\Monetico\Configuration;

/**
 * Sandbox or production, and the three URLs that follow from the choice.
 *
 * They are not one host with a prefix: the payment form lives on
 * p.monetico-services.com and the two server-to-server services live on
 * payment-api.e-i.com. Older integrations guessed the second from the first
 * and found out on the day they tried to refund somebody.
 *
 * The enum also answers the question that matters once a shop is live: a
 * notification whose result is `payetest` is a sandbox payment, and the
 * documentation is blunt that seeing it in production is an anomaly. See
 * Notification::looksLikeWrongEnvironment().
 */
enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function paymentUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://p.monetico-services.com/test/paiement.cgi',
            self::Production => 'https://p.monetico-services.com/paiement.cgi',
        };
    }

    public function captureUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://payment-api.e-i.com/test/capture_paiement.cgi',
            self::Production => 'https://payment-api.e-i.com/capture_paiement.cgi',
        };
    }

    public function refundUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://payment-api.e-i.com/test/recredit_paiement.cgi',
            self::Production => 'https://payment-api.e-i.com/recredit_paiement.cgi',
        };
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
