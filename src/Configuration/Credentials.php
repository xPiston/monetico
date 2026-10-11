<?php

declare(strict_types=1);

namespace Piston\Monetico\Configuration;

use Piston\Monetico\Field\CompanyCode;
use Piston\Monetico\Field\TerminalNumber;
use Piston\Monetico\Security\SecurityKey;
use SensitiveParameter;

/**
 * The three things Monetico gives a merchant: a terminal, a key and a company
 * code.
 *
 * One object rather than three constructor arguments because they are issued
 * together, replaced together, and differ together between the sandbox and
 * production. A shop that mixes a production terminal with a sandbox key gets
 * a seal error, and the fastest way to make that mistake is to configure the
 * three separately.
 */
final class Credentials
{
    public function __construct(
        public readonly TerminalNumber $terminal,
        public readonly SecurityKey $key,
        public readonly CompanyCode $company,
    ) {}

    /** The shape configuration files have: three strings, validated here. */
    public static function fromStrings(
        string $terminal,
        #[SensitiveParameter] string $key,
        string $company,
    ): self {
        return new self(
            TerminalNumber::of($terminal),
            SecurityKey::fromHex($key),
            CompanyCode::of($company),
        );
    }
}
