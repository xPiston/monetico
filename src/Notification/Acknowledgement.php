<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

use Stringable;

/**
 * The receipt the notification endpoint must write back.
 *
 * Four lines of specification that are easy to get wrong in three ways.
 *
 * **It depends only on the seal.** Not on whether the payment succeeded, not
 * on whether the shop managed to save the order. A refused payment with a
 * valid seal is acknowledged with `cdr=0`: the message was received and
 * understood. Answering `cdr=1` because the payment failed makes the platform
 * email the merchant and send the whole thing again.
 *
 * **The separator is a line feed**, and there is one at the end too.
 *
 * **It must arrive within fifteen seconds.** Which is a design constraint on
 * the handler, not on this class: whatever the shop does with a payment -
 * emails, invoices, stock - belongs after the acknowledgement, in a queue.
 */
final class Acknowledgement implements Stringable
{
    public const VERSION = '2';

    private function __construct(private readonly bool $sealIsValid) {}

    public static function accepted(): self
    {
        return new self(true);
    }

    public static function rejected(): self
    {
        return new self(false);
    }

    /** The whole decision, in one call: was the seal valid? */
    public static function for(bool $sealIsValid): self
    {
        return new self($sealIsValid);
    }

    public function body(): string
    {
        return sprintf("version=%s\ncdr=%d\n", self::VERSION, $this->sealIsValid ? 0 : 1);
    }

    public function contentType(): string
    {
        return 'text/plain';
    }

    public function isAcceptance(): bool
    {
        return $this->sealIsValid;
    }

    public function __toString(): string
    {
        return $this->body();
    }
}
