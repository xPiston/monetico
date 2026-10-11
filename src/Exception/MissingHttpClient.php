<?php

declare(strict_types=1);

namespace Piston\Monetico\Exception;

use LogicException;

/**
 * Capture and refund need an HTTP client; the payment form does not.
 *
 * This library asks for no HTTP client in its constructor so that the common
 * case - rendering a sealed form and verifying the notification - installs
 * nothing extra. The two server-to-server services are the exception, and this
 * is what they say when nothing was wired.
 */
final class MissingHttpClient extends LogicException implements MoneticoException
{
    public static function create(): self
    {
        return new self(
            'The capture and refund services need a PSR-18 client. Pass one to Monetico::__construct(), '
            .'or install php-http/discovery together with a client (symfony/http-client, guzzlehttp/guzzle) and it will be found.',
        );
    }
}
