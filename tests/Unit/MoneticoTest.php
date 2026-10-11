<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Configuration\Environment;
use Piston\Monetico\Exception\SealMismatch;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\Reference;
use Piston\Monetico\Monetico;
use Piston\Monetico\Notification\NotificationVerifier;
use Piston\Monetico\Payment\PaymentRequest;
use Piston\Monetico\Security\SealVerifier;
use Piston\Monetico\Tests\Support\FakePlatform;
use Piston\Monetico\Tests\Support\Fixtures;

final class MoneticoTest extends TestCase
{
    public function test_the_sandbox_shortcut(): void
    {
        $monetico = Monetico::sandbox(Fixtures::TERMINAL, Fixtures::KEY, Fixtures::COMPANY);

        $this->assertSame(Environment::Sandbox, $monetico->environment);
        $this->assertSame('1234567', $monetico->credentials->terminal->value());
    }

    public function test_the_production_shortcut(): void
    {
        $monetico = Monetico::production(Fixtures::TERMINAL, Fixtures::KEY, Fixtures::COMPANY);

        $this->assertSame(Environment::Production, $monetico->environment);
    }

    public function test_the_default_environment_is_the_one_that_cannot_take_real_money(): void
    {
        $this->assertSame(Environment::Sandbox, (new Monetico(Fixtures::credentials()))->environment);
    }

    public function test_the_same_shop_can_be_pointed_at_the_other_environment(): void
    {
        $sandbox = Monetico::sandbox(Fixtures::TERMINAL, Fixtures::KEY, Fixtures::COMPANY);
        $production = $sandbox->using(Environment::Production);

        $this->assertSame(Environment::Sandbox, $sandbox->environment, 'the original is unchanged');
        $this->assertSame(Environment::Production, $production->environment);
        $this->assertSame($sandbox->credentials, $production->credentials);
    }

    public function test_the_form_goes_to_the_environment_url(): void
    {
        $request = new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: new DateTimeImmutable('2026-10-12 11:00:00', new DateTimeZone('Europe/Paris')),
        );

        $monetico = Monetico::sandbox(Fixtures::TERMINAL, Fixtures::KEY, Fixtures::COMPANY);

        $this->assertSame(Environment::Sandbox->paymentUrl(), $monetico->form($request)->action());
        $this->assertSame(
            Environment::Production->paymentUrl(),
            $monetico->using(Environment::Production)->form($request)->action(),
        );
    }

    public function test_the_form_is_sealed_with_the_current_algorithm(): void
    {
        $monetico = Monetico::sandbox(Fixtures::TERMINAL, Fixtures::KEY, Fixtures::COMPANY);

        $form = $monetico->form(new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: new DateTimeImmutable('2026-10-12 11:00:00', new DateTimeZone('Europe/Paris')),
        ));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $form->seal());
        $this->assertSame($form->seal(), $form->fields()['MAC']);
    }

    public function test_it_hands_out_a_verifier(): void
    {
        $monetico = Monetico::sandbox(Fixtures::TERMINAL, Fixtures::KEY, Fixtures::COMPANY);

        $this->assertInstanceOf(NotificationVerifier::class, $monetico->verifier());
    }

    public function test_the_verifier_it_hands_out_honours_the_configured_algorithms(): void
    {
        $post = FakePlatform::legacy(Fixtures::credentials())->acceptedPayment('REF001', '10.00EUR');

        $lenient = new Monetico(Fixtures::credentials());
        $strict = new Monetico(Fixtures::credentials(), seals: SealVerifier::strict());

        $this->assertNotNull($lenient->attemptNotification($post));
        $this->assertNull($strict->attemptNotification($post));
    }

    public function test_notification_throws_and_attempt_does_not(): void
    {
        $monetico = Monetico::sandbox(Fixtures::TERMINAL, Fixtures::KEY, Fixtures::COMPANY);
        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF001', '10.00EUR');
        $post['montant'] = '0.01EUR';

        $this->assertNull($monetico->attemptNotification($post));

        $this->expectException(SealMismatch::class);

        $monetico->notification($post);
    }

    public function test_the_timezone_can_be_set_for_the_whole_shop(): void
    {
        $monetico = new Monetico(Fixtures::credentials(), timezone: new DateTimeZone('UTC'));

        $form = $monetico->form(new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: new DateTimeImmutable('2026-06-24 08:00:25', new DateTimeZone('UTC')),
        ));

        $this->assertSame('24/06/2026:08:00:25', $form->fields()['date']);
        $this->assertSame(
            '24/06/2026:08:00:25',
            $monetico->using(Environment::Production)->form(new PaymentRequest(
                reference: Reference::of('REF001'),
                amount: Amount::fromMinor(100, 'EUR'),
                orderedAt: new DateTimeImmutable('2026-06-24 08:00:25', new DateTimeZone('UTC')),
            ))->fields()['date'],
            'and it survives a change of environment',
        );
    }
}
