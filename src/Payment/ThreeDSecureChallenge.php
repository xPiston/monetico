<?php

declare(strict_types=1);

namespace Piston\Monetico\Payment;

/**
 * What the shop would like 3D Secure to do - a preference, not an instruction.
 *
 * The issuer decides. Asking for no challenge on a two-euro order is a
 * reasonable thing to want and is granted often; asking for it on a stored
 * card is pointless, because the documentation says `challenge_mandated` is
 * applied to card registration whatever the shop asks for.
 */
enum ThreeDSecureChallenge: string
{
    case NoPreference = 'no_preference';
    case ChallengePreferred = 'challenge_preferred';
    case ChallengeMandated = 'challenge_mandated';
    case NoChallengeRequested = 'no_challenge_requested';
    case NoChallengeStrongAuthenticationDone = 'no_challenge_requested_strong_authentication';
    case NoChallengeTrustedThirdParty = 'no_challenge_requested_trusted_third_party';
    case NoChallengeRiskAnalysis = 'no_challenge_requested_risk_analysis';
}
