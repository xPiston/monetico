<?php

declare(strict_types=1);

namespace Piston\Monetico\Bridge\Symfony;

use Symfony\Component\HttpFoundation\Request;

/**
 * The fields of a notification, read out of a Symfony request.
 *
 * `$request->request->all()` - the form body, and only the form body. A query
 * parameter on the notification route would otherwise join the fields the seal
 * is computed over, and the platform never sealed it.
 */
final class NotificationRequest
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function fields(Request $request): array
    {
        return $request->request->all();
    }
}
