<?php

declare(strict_types=1);

namespace Piston\Monetico\Bridge\Laravel;

use Illuminate\Http\Response;
use Piston\Monetico\Notification\Acknowledgement;

/**
 * The acknowledgement as a Laravel response: `text/plain`, status 200, exact
 * bytes.
 *
 * Status 200 even when the seal was rejected. The platform reads the body, not
 * the status code, and an error status would be logged as the endpoint being
 * down - which brings a retry and a warning email rather than the clear "this
 * message was refused" that `cdr=1` is.
 */
final class AcknowledgementResponse
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function for(Acknowledgement $acknowledgement): Response
    {
        return new Response($acknowledgement->body(), 200, [
            'Content-Type' => $acknowledgement->contentType(),
        ]);
    }
}
