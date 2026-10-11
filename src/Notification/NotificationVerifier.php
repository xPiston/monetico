<?php

declare(strict_types=1);

namespace Piston\Monetico\Notification;

use Piston\Monetico\Configuration\Credentials;
use Piston\Monetico\Exception\SealMismatch;
use Piston\Monetico\Security\SealVerifier;

/**
 * Turns an incoming request into a notification you may act on - or refuses.
 *
 * Two checks, in this order, and neither is optional.
 *
 * **The terminal.** A notification naming another `TPE` was sealed with
 * another key; there is nothing here that could verify it, and it is not this
 * shop's payment.
 *
 * **The seal**, recomputed over *every* field received, with the current
 * algorithm and then with the one orders created before the migration still
 * use.
 *
 * What comes back is the same Notification, carrying the algorithm that
 * vouched for it. What comes back on failure is an exception - and the caller
 * still owes the platform an answer: `Acknowledgement::rejected()`.
 */
final class NotificationVerifier
{
    public function __construct(
        private readonly Credentials $credentials,
        private readonly SealVerifier $seals = new SealVerifier,
    ) {}

    /**
     * @param  array<string, mixed>  $post
     *
     * @throws SealMismatch
     */
    public function verify(array $post): Notification
    {
        $notification = Notification::fromArray($post);

        $terminal = $notification->terminal();

        if ($terminal === null || ! $terminal->equals($this->credentials->terminal)) {
            throw SealMismatch::wrongTerminal(
                $this->credentials->terminal->value(),
                $terminal?->value() ?? '(none)',
            );
        }

        $seal = $notification->seal();

        if ($seal === null) {
            throw SealMismatch::missing();
        }

        $algorithm = $this->seals->matching($notification->fields(), $seal, $this->credentials->key);

        if ($algorithm === null) {
            throw SealMismatch::create();
        }

        return $notification->withSealAlgorithm($algorithm);
    }

    /**
     * The same thing without the exception, for a handler that wants to write
     * one `if` and answer either way.
     *
     * @param  array<string, mixed>  $post
     */
    public function attempt(array $post): ?Notification
    {
        try {
            return $this->verify($post);
        } catch (SealMismatch) {
            return null;
        }
    }
}
