<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment;

use DateTimeImmutable;
use Piston\Monetico\Field\Amount;

/** One due date of a split payment: when, and how much. */
final class Instalment
{
    public function __construct(
        public readonly DateTimeImmutable $dueOn,
        public readonly Amount $amount,
    ) {}
}
