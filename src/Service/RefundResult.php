<?php

declare(strict_types=1);

namespace Piston\Monetico\Service;

use Piston\Monetico\Field\Amount;
use Throwable;

/**
 * The answer to a refund.
 *
 * `cdr=0` is success here. It is the opposite of the capture service, where 0
 * is a refusal, and it is why this is a different class rather than a flag on
 * a shared one: the two replies look identical and mean opposite things.
 */
final class RefundResult
{
    private function __construct(
        public readonly bool $completed,
        public readonly ?RefundError $error,
        public readonly ServiceReply $reply,
    ) {}

    public static function from(ServiceReply $reply): self
    {
        $code = $reply->returnCode();

        return new self(
            completed: $code === 0,
            error: $code === 0 ? null : RefundError::tryFrom($code),
            reply: $reply,
        );
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    /** Was the money already back with the customer before this request? */
    public function isAlreadyDone(): bool
    {
        return $this->error?->isAlreadyDone() ?? false;
    }

    public function isRetryable(): bool
    {
        return $this->error?->isRetryable() ?? false;
    }

    public function reference(): ?string
    {
        return $this->reply->get('reference');
    }

    public function authorisationNumber(): ?string
    {
        return $this->reply->get('aut');
    }

    /** `montant_recredit`, echoed back on a pre-authorisation terminal. */
    public function refundedAmount(): ?Amount
    {
        $raw = $this->reply->get('montant_recredit');

        if ($raw === null) {
            return null;
        }

        try {
            return Amount::fromString($raw);
        } catch (Throwable) {
            return null;
        }
    }

    public function label(): string
    {
        return $this->reply->label();
    }
}
