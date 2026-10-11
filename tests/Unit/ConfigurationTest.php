<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Configuration\Credentials;
use Piston\Monetico\Configuration\Environment;
use Piston\Monetico\Exception\InvalidField;
use Piston\Monetico\Tests\Support\Fixtures;

final class ConfigurationTest extends TestCase
{
    public function test_credentials_are_validated_when_they_are_read_from_configuration(): void
    {
        $credentials = Credentials::fromStrings(Fixtures::TERMINAL, Fixtures::KEY, Fixtures::COMPANY);

        $this->assertSame('1234567', $credentials->terminal->value());
        $this->assertSame('monSite1', $credentials->company->value());
        $this->assertSame(20, strlen($credentials->key->bytes()));
    }

    #[DataProvider('brokenCredentials')]
    public function test_a_broken_credential_is_refused_at_boot_and_not_at_the_first_payment(
        string $terminal,
        string $key,
        string $company,
    ): void {
        $this->expectException(InvalidField::class);

        Credentials::fromStrings($terminal, $key, $company);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function brokenCredentials(): iterable
    {
        yield 'no terminal' => ['', Fixtures::KEY, Fixtures::COMPANY];
        yield 'terminal too long' => ['12345678', Fixtures::KEY, Fixtures::COMPANY];
        yield 'key truncated' => [Fixtures::TERMINAL, substr(Fixtures::KEY, 0, 39), Fixtures::COMPANY];
        yield 'key is not hexadecimal' => [Fixtures::TERMINAL, str_repeat('z', 40), Fixtures::COMPANY];
        yield 'no company code' => [Fixtures::TERMINAL, Fixtures::KEY, ''];
    }

    /**
     * The sandbox and production differ in three URLs, and two of them are on
     * a different host from the payment page.
     */
    public function test_the_sandbox_urls(): void
    {
        $this->assertSame('https://p.monetico-services.com/test/paiement.cgi', Environment::Sandbox->paymentUrl());
        $this->assertSame('https://payment-api.e-i.com/test/capture_paiement.cgi', Environment::Sandbox->captureUrl());
        $this->assertSame('https://payment-api.e-i.com/test/recredit_paiement.cgi', Environment::Sandbox->refundUrl());
        $this->assertFalse(Environment::Sandbox->isProduction());
    }

    public function test_the_production_urls(): void
    {
        $this->assertSame('https://p.monetico-services.com/paiement.cgi', Environment::Production->paymentUrl());
        $this->assertSame('https://payment-api.e-i.com/capture_paiement.cgi', Environment::Production->captureUrl());
        $this->assertSame('https://payment-api.e-i.com/recredit_paiement.cgi', Environment::Production->refundUrl());
        $this->assertTrue(Environment::Production->isProduction());
    }

    public function test_the_production_urls_are_the_sandbox_ones_without_the_test_segment(): void
    {
        foreach (['paymentUrl', 'captureUrl', 'refundUrl'] as $url) {
            $this->assertSame(
                str_replace('/test/', '/', Environment::Sandbox->$url()),
                Environment::Production->$url(),
            );
        }
    }

    public function test_the_environment_reads_back_from_a_configuration_string(): void
    {
        $this->assertSame(Environment::Sandbox, Environment::from('sandbox'));
        $this->assertSame(Environment::Production, Environment::from('production'));
        $this->assertNull(Environment::tryFrom('staging'));
    }
}
