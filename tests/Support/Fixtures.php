<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Support;

use Piston\Monetico\Configuration\Credentials;

/** The terminal, key and company code used throughout the suite. */
final class Fixtures
{
    /** The example key from the documentation. */
    public const KEY = '0123456789ABCDEF0123456789ABCDEF01234567';

    public const TERMINAL = '1234567';

    public const COMPANY = 'monSite1';

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function credentials(): Credentials
    {
        return Credentials::fromStrings(self::TERMINAL, self::KEY, self::COMPANY);
    }
}
