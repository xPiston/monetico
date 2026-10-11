<?php

declare(strict_types=1);

namespace Piston\Monetico\Security;

/**
 * The seal notifications used before the alphabetical one: a fixed sequence of
 * values, joined by `*`, with the version wedged in the middle and a trailing
 * separator.
 *
 * This is not here for nostalgia. The platform seals a notification with the
 * method the *order* was created with, and an instalment plan can call back
 * four months after its first payment. So a shop that migrates mid-plan gets
 * both kinds of notification for a while, and the only safe reading of that is
 * to accept either - which is what SealVerifier does by default.
 *
 * The sequence, from the appendix of the technical documentation:
 *
 *     TPE*date*montant*reference*texte-libre*3.0*code-retour*cvx*vld*brand*
 *     status3ds*numauto*motifrefus*originecb*bincb*hpancb*ipclient*originetr*
 *     veres*pares*
 *
 * Missing fields are empty, not absent: the separators are positional, so a
 * payment with no refusal reason still carries the `**` where it would have
 * been.
 */
final class LegacyPositionalSeal extends SealAlgorithm
{
    /**
     * The documented order. `null` marks the slot where the literal version
     * sits rather than a field.
     *
     * @var list<string|null>
     */
    private const SEQUENCE = [
        'TPE',
        'date',
        'montant',
        'reference',
        'texte-libre',
        null,
        'code-retour',
        'cvx',
        'vld',
        'brand',
        'status3ds',
        'numauto',
        'motifrefus',
        'originecb',
        'bincb',
        'hpancb',
        'ipclient',
        'originetr',
        'veres',
        'pares',
    ];

    private const VERSION = '3.0';

    public function name(): string
    {
        return 'legacy-positional';
    }

    public function canonicalString(array $fields): string
    {
        $values = [];

        foreach (self::SEQUENCE as $name) {
            if ($name === null) {
                $values[] = self::VERSION;

                continue;
            }

            $values[] = (string) ($fields[$name] ?? '');
        }

        // The trailing separator is in the documented example and is part of
        // the hashed string: dropping it makes every seal wrong.
        return implode(AlphabeticalSeal::SEPARATOR, $values).AlphabeticalSeal::SEPARATOR;
    }
}
