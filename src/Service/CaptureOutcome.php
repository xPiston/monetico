<?php

declare(strict_types=1);

namespace Piston\Monetico\Service;

/**
 * What `cdr` means in a capture reply: 1 accepted, 0 refused, -1 error.
 *
 * Which is worth spelling out in a type, because `cdr` means something
 * different in each of the three places it appears in this protocol. Here 0 is
 * a refusal; in a refund reply 0 is success; in the acknowledgement a shop
 * writes back to the notification endpoint, 0 means the seal was valid. A
 * single integer carrying three contradictory meanings is the sort of thing a
 * library exists to absorb.
 */
enum CaptureOutcome: int
{
    case Accepted = 1;
    case Refused = 0;
    case Error = -1;

    public static function fromReturnCode(int $code): self
    {
        return self::tryFrom($code) ?? self::Error;
    }
}
