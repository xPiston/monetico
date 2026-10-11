<?php

declare(strict_types=1);

namespace Piston\Monetico\Bridge\Symfony;

use Piston\Monetico\Notification\Acknowledgement;
use Symfony\Component\HttpFoundation\Response;

/**
 * The acknowledgement as a Symfony response: `text/plain`, status 200,
 * whether the seal was accepted or refused.
 *
 * The body carries the verdict. A 4xx would be read as a broken endpoint and
 * bring the notification back a second time.
 */
final class AcknowledgementResponse
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function for(Acknowledgement $acknowledgement): Response
    {
        return new Response($acknowledgement->body(), Response::HTTP_OK, [
            'Content-Type' => $acknowledgement->contentType(),
        ]);
    }
}
