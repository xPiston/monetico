<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Support;

use Piston\Monetico\Configuration\Credentials;
use Piston\Monetico\Security\AlphabeticalSeal;
use Piston\Monetico\Security\LegacyPositionalSeal;
use Piston\Monetico\Security\SealAlgorithm;

/**
 * Monetico's side of the exchange: builds the notification the platform would
 * post, and seals it with the merchant's key.
 *
 * Which is the only honest way to test a verifier. A test that asserts
 * "verification fails on a wrong seal" proves nothing on its own - an
 * implementation that rejects everything passes it. The pair that matters is
 * this: a notification sealed exactly as the platform seals it is accepted,
 * and the same notification with one byte changed is not.
 *
 * It also does the thing the real sandbox does and nobody expects:
 * `withRandomField()` adds a field with a random name, because the platform
 * adds one to every sandbox payment "in order to guarantee your implementation
 * and the right management of every new parameter". Any verifier that rebuilds
 * the sealed string from a list of known field names fails that one test and
 * passes the rest.
 */
final class FakePlatform
{
    private function __construct(
        private readonly Credentials $credentials,
        private readonly SealAlgorithm $algorithm,
    ) {}

    public static function for(Credentials $credentials): self
    {
        return new self($credentials, new AlphabeticalSeal);
    }

    /** The platform as it was before the seal calculation changed. */
    public static function legacy(Credentials $credentials): self
    {
        return new self($credentials, new LegacyPositionalSeal);
    }

    /**
     * An accepted payment, as the "Response" interface posts it.
     *
     * The field values are the ones from the documentation's own example
     * payload, with the terminal and the order taken from the arguments.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public function acceptedPayment(string $reference, string $amount, array $extra = []): array
    {
        return $this->seal([
            'TPE' => $this->credentials->terminal->value(),
            'date' => '05/12/2006_a_11:55:23',
            'montant' => $amount,
            'reference' => $reference,
            'MAC' => '',
            'texte-libre' => 'LeTexteLibre',
            'code-retour' => 'paiement',
            'cvx' => 'oui',
            'vld' => '1208',
            'brand' => 'VI',
            'numauto' => '010101',
            'originecb' => 'FRA',
            'bincb' => '010101',
            'hpancb' => '74E94B03C22D786E0F2C2CADBFC1C00B004B7C45',
            'ipclient' => '127.0.0.1',
            'originetr' => 'FRA',
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public function refusedPayment(string $reference, string $amount, array $extra = []): array
    {
        return $this->acceptedPayment($reference, $amount, [
            'code-retour' => 'Annulation',
            'motifrefus' => 'Refus',
            'motifrefusautorisation' => 'Refus banque',
            'numauto' => '',
            ...$extra,
        ]);
    }

    /**
     * The same payment with the field the sandbox invents for every
     * notification.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    public function withRandomField(array $fields, string $name = 'zTestFutureField', string $value = 'unexpected'): array
    {
        $fields[$name] = $value;
        unset($fields['MAC']);

        return $this->seal([...$fields, 'MAC' => '']);
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private function seal(array $fields): array
    {
        unset($fields['MAC']);
        $fields['MAC'] = $this->algorithm->seal($fields, $this->credentials->key)->value();

        return $fields;
    }
}
