<?php

declare(strict_types=1);

namespace Piston\Monetico\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * The capture or refund service could not be reached, or answered something
 * that is not a service reply.
 *
 * Kept distinct from a *refused* capture: a refusal is an answer and is
 * returned as a result object, while this is the absence of an answer. The
 * first is business, the second is retryable.
 */
final class ServiceFailed extends RuntimeException implements MoneticoException
{
    public static function transport(ClientExceptionInterface $previous): self
    {
        return new self('The Monetico service could not be reached: '.$previous->getMessage(), 0, $previous);
    }

    public static function status(int $status, string $body): self
    {
        return new self(sprintf('The Monetico service answered HTTP %d: %s', $status, self::excerpt($body)));
    }

    public static function unreadableReply(string $body): self
    {
        return new self(sprintf(
            'The Monetico service answered something that is not a "key=value" reply: %s',
            self::excerpt($body),
        ));
    }

    private static function excerpt(string $body): string
    {
        $body = trim($body);

        return mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'...' : $body;
    }
}
