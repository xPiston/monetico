<?php

declare(strict_types=1);

namespace Piston\Monetico\Service;

/** The answer to a capture, a cancellation or a recurrence stop. */
final class CaptureResult
{
    private function __construct(
        public readonly CaptureOutcome $outcome,
        public readonly ?CaptureReason $reason,
        public readonly ServiceReply $reply,
    ) {}

    public static function from(ServiceReply $reply): self
    {
        return new self(
            CaptureOutcome::fromReturnCode($reply->returnCode()),
            CaptureReason::tryFromWire($reply->label()),
            $reply,
        );
    }

    public function isAccepted(): bool
    {
        return $this->outcome === CaptureOutcome::Accepted;
    }

    /**
     * Is this a failure worth trying again?
     *
     * Only ever true for an `Error`: a *refusal* is an answer - the bank said
     * no, the order was already cancelled - and sending it again gets the same
     * answer.
     */
    public function isRetryable(): bool
    {
        return $this->outcome === CaptureOutcome::Error && ($this->reason?->isRetryable() ?? false);
    }

    public function authorisationNumber(): ?string
    {
        return $this->reply->get('aut');
    }

    public function reference(): ?string
    {
        return $this->reply->get('reference');
    }

    /** Set when the authorisation needs a phone call to the card's issuer. */
    public function needsPhoneCall(): bool
    {
        return $this->reply->get('phonie') !== null;
    }

    /** The platform's own wording, for a log or an admin screen. */
    public function label(): string
    {
        return $this->reply->label();
    }
}
