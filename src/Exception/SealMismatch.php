<?php

declare(strict_types=1);

namespace Piston\Monetico\Exception;

use RuntimeException;

/**
 * The seal on an incoming notification is not the one we computed.
 *
 * Which means one of three things: the message was tampered with, it was not
 * sent by Monetico, or it was sealed with a key other than the one configured
 * (a key regenerated and not deployed everywhere does exactly this).
 *
 * None of them is a payment. An application that catches this must still
 * answer the platform - with `Acknowledgement::rejected()` - and must not
 * touch the order.
 */
final class SealMismatch extends RuntimeException implements MoneticoException
{
    public static function create(): self
    {
        return new self('The seal of this notification does not match the one computed from its fields: the message is not trustworthy.');
    }

    public static function missing(): self
    {
        return new self('This notification carries no MAC field, so there is nothing to verify.');
    }

    public static function wrongTerminal(string $expected, string $given): self
    {
        return new self(sprintf('This notification is for terminal %s, not %s.', $given, $expected));
    }
}
