<?php

declare(strict_types=1);

namespace Piston\Monetico\Bridge\Laravel;

use Illuminate\Http\Request;

/**
 * The fields of a notification, read out of a Laravel request.
 *
 * It exists because `$request->all()` is the wrong call here, and it is the
 * obvious one. That method merges the query string into the body, so a
 * notification URL registered as `/monetico/notify?shop=42` would put `shop`
 * into the seal's string - a field the platform never sent and never sealed -
 * and every payment would fail verification with nothing in the logs to
 * suggest why.
 *
 * The platform posts a form body. This reads the form body.
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
