<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Piston\Monetico\Security\AlphabeticalSeal;
use Piston\Monetico\Security\LegacyPositionalSeal;
use Piston\Monetico\Security\Seal;
use Piston\Monetico\Security\SealVerifier;
use Piston\Monetico\Tests\Support\Fixtures;

final class SealVerifierTest extends TestCase
{
    public function test_it_accepts_a_seal_from_the_current_algorithm(): void
    {
        $key = Fixtures::credentials()->key;
        $fields = ['TPE' => '1234567', 'montant' => '10EUR'];
        $seal = (new AlphabeticalSeal)->seal($fields, $key);

        $matched = (new SealVerifier)->matching($fields, $seal, $key);

        $this->assertInstanceOf(AlphabeticalSeal::class, $matched);
    }

    /**
     * The reason this class exists: an instalment created before the
     * migration still calls back, sealed the old way, months later.
     */
    public function test_it_accepts_a_seal_from_the_algorithm_orders_used_before_the_migration(): void
    {
        $key = Fixtures::credentials()->key;
        $fields = ['TPE' => '1234567', 'montant' => '10EUR', 'code-retour' => 'paiement_pf3'];
        $seal = (new LegacyPositionalSeal)->seal($fields, $key);

        $matched = (new SealVerifier)->matching($fields, $seal, $key);

        $this->assertInstanceOf(LegacyPositionalSeal::class, $matched);
    }

    public function test_it_reports_which_algorithm_vouched_for_the_message(): void
    {
        $key = Fixtures::credentials()->key;
        $fields = ['TPE' => '1234567'];

        $this->assertSame(
            'legacy-positional',
            (new SealVerifier)->matching($fields, (new LegacyPositionalSeal)->seal($fields, $key), $key)?->name(),
        );
    }

    public function test_it_refuses_a_seal_from_neither(): void
    {
        $key = Fixtures::credentials()->key;

        $this->assertNull(
            (new SealVerifier)->matching(['TPE' => '1234567'], Seal::fromHex(str_repeat('ab', 20)), $key),
        );
    }

    public function test_it_refuses_a_seal_computed_with_another_key(): void
    {
        $fields = ['TPE' => '1234567', 'montant' => '10EUR'];
        $theirs = (new AlphabeticalSeal)->seal($fields, Fixtures::credentials()->key);
        $ours = \Piston\Monetico\Security\SecurityKey::fromHex(str_repeat('ff', 20));

        $this->assertNull((new SealVerifier)->matching($fields, $theirs, $ours));
    }

    public function test_strict_accepts_only_the_current_algorithm(): void
    {
        $key = Fixtures::credentials()->key;
        $fields = ['TPE' => '1234567'];

        $verifier = SealVerifier::strict();

        $this->assertCount(1, $verifier->algorithms());
        $this->assertNotNull($verifier->matching($fields, (new AlphabeticalSeal)->seal($fields, $key), $key));
        $this->assertNull($verifier->matching($fields, (new LegacyPositionalSeal)->seal($fields, $key), $key));
    }

    public function test_it_tries_the_current_algorithm_first(): void
    {
        $this->assertInstanceOf(AlphabeticalSeal::class, (new SealVerifier)->algorithms()[0]);
    }
}
