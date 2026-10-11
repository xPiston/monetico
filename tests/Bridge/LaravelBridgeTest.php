<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Bridge;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase;
use Piston\Monetico\Bridge\Laravel\AcknowledgementResponse;
use Piston\Monetico\Bridge\Laravel\MoneticoServiceProvider;
use Piston\Monetico\Bridge\Laravel\NotificationRequest;
use Piston\Monetico\Configuration\Environment;
use Piston\Monetico\Exception\SealMismatch;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\Reference;
use Piston\Monetico\Monetico;
use Piston\Monetico\Notification\Acknowledgement;
use Piston\Monetico\Notification\NotificationVerifier;
use Piston\Monetico\Payment\PaymentRequest;
use Piston\Monetico\Tests\Support\FakePlatform;
use Piston\Monetico\Tests\Support\Fixtures;
use RuntimeException;

/**
 * The Laravel bridge, in a real Laravel application.
 *
 * Testbench boots the actual framework rather than a mock of it, because what
 * is being tested is wiring: a provider that registers a singleton the
 * container cannot build is not something a unit test notices.
 */
final class LaravelBridgeTest extends TestCase
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [MoneticoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set([
            'monetico.terminal' => Fixtures::TERMINAL,
            'monetico.key' => Fixtures::KEY,
            'monetico.company' => Fixtures::COMPANY,
            'monetico.environment' => 'sandbox',
        ]);
    }

    /** Testbench's application property is nullable between tests. */
    private function laravel(): Application
    {
        $app = $this->app;

        $this->assertInstanceOf(Application::class, $app);

        return $app;
    }

    public function test_it_resolves_a_configured_client(): void
    {
        $monetico = $this->laravel()->make(Monetico::class);

        $this->assertInstanceOf(Monetico::class, $monetico);
        $this->assertSame(Fixtures::TERMINAL, $monetico->credentials->terminal->value());
        $this->assertSame(Fixtures::COMPANY, $monetico->credentials->company->value());
        $this->assertSame(Environment::Sandbox, $monetico->environment);
    }

    public function test_it_is_a_singleton(): void
    {
        $this->assertSame($this->laravel()->make(Monetico::class), $this->laravel()->make(Monetico::class));
    }

    public function test_the_verifier_resolves_on_its_own(): void
    {
        $verifier = $this->laravel()->make(NotificationVerifier::class);

        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF001', '10.00EUR');

        $this->assertTrue($verifier->verify($post)->isAccepted());
    }

    public function test_the_default_config_file_is_merged(): void
    {
        // Published or not, the keys exist with their defaults.
        $this->assertTrue(config('monetico.accept_legacy_seals'));
        $this->assertSame('Europe/Paris', config('monetico.timezone'));
    }

    public function test_the_config_file_can_be_published(): void
    {
        $command = $this->artisan('vendor:publish', ['--tag' => 'monetico-config']);

        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful();

        $this->assertFileExists($this->laravel()->configPath('monetico.php'));

        @unlink($this->laravel()->configPath('monetico.php'));
    }

    public function test_production_is_a_configuration_choice_and_not_the_kernel_environment(): void
    {
        config(['monetico.environment' => 'production']);
        $this->refreshApplication();
        $this->defineEnvironment($this->laravel());
        config(['monetico.environment' => 'production']);

        $this->assertSame(Environment::Production, $this->laravel()->make(Monetico::class)->environment);
    }

    public function test_a_missing_terminal_says_what_to_set(): void
    {
        config(['monetico.terminal' => null]);
        $this->laravel()->forgetInstance(Monetico::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MONETICO_TERMINAL is missing');

        $this->laravel()->make(Monetico::class);
    }

    public function test_a_missing_key_says_what_to_set(): void
    {
        config(['monetico.key' => '']);
        $this->laravel()->forgetInstance(Monetico::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MONETICO_KEY is missing');

        $this->laravel()->make(Monetico::class);
    }

    public function test_an_environment_that_is_neither_is_refused(): void
    {
        config(['monetico.environment' => 'staging']);
        $this->laravel()->forgetInstance(Monetico::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be "sandbox" or "production", got "staging"');

        $this->laravel()->make(Monetico::class);
    }

    public function test_the_legacy_seal_can_be_switched_off_from_the_config(): void
    {
        $post = FakePlatform::legacy(Fixtures::credentials())->acceptedPayment('REF001', '10.00EUR');

        $this->assertTrue($this->laravel()->make(Monetico::class)->notification($post)->isAccepted());

        config(['monetico.accept_legacy_seals' => false]);
        $this->laravel()->forgetInstance(Monetico::class);

        $this->expectException(SealMismatch::class);

        $this->laravel()->make(Monetico::class)->notification($post);
    }

    public function test_the_configured_timezone_is_the_one_the_dates_are_sent_in(): void
    {
        config(['monetico.timezone' => 'UTC']);
        $this->laravel()->forgetInstance(Monetico::class);

        $form = $this->laravel()->make(Monetico::class)->form(new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: new DateTimeImmutable('2026-06-24 08:00:25', new DateTimeZone('UTC')),
        ));

        $this->assertSame('24/06/2026:08:00:25', $form->fields()['date']);
    }

    /**
     * The trap this helper exists for: `$request->all()` merges the query
     * string into the body, so a notification route with a query parameter
     * would put a field the platform never sealed into the seal's string.
     */
    public function test_the_notification_fields_come_from_the_body_and_not_the_query_string(): void
    {
        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF001', '10.00EUR');

        $request = Request::create('/monetico/notify?shop=42&source=newsletter', 'POST', $post);

        $fields = NotificationRequest::fields($request);

        $this->assertArrayNotHasKey('shop', $fields);
        $this->assertArrayNotHasKey('source', $fields);
        $this->assertSame($post, $fields);

        // And the seal still verifies, which it would not have done with
        // `$request->all()`.
        $this->assertTrue($this->laravel()->make(Monetico::class)->notification($fields)->isAccepted());
    }

    public function test_the_acknowledgement_is_plain_text_with_a_two_hundred(): void
    {
        $response = AcknowledgementResponse::for(Acknowledgement::accepted());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame("version=2\ncdr=0\n", $response->getContent());
    }

    /** A refused seal is still answered with 200: the body carries the verdict. */
    public function test_a_rejected_acknowledgement_is_also_a_two_hundred(): void
    {
        $response = AcknowledgementResponse::for(Acknowledgement::rejected());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame("version=2\ncdr=1\n", $response->getContent());
    }

    public function test_the_provider_declares_what_it_provides(): void
    {
        $provider = new MoneticoServiceProvider($this->laravel());

        $this->assertSame([Monetico::class, NotificationVerifier::class], $provider->provides());
    }

    /**
     * A notification handler, end to end, as an application would write it.
     */
    public function test_a_controller_sized_example(): void
    {
        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF7896543', '62.73EUR');
        $request = Request::create('/monetico/notify', 'POST', $post);

        $verifier = $this->laravel()->make(NotificationVerifier::class);
        $notification = $verifier->attempt(NotificationRequest::fields($request));

        $response = AcknowledgementResponse::for(Acknowledgement::for($notification !== null));

        $this->assertNotNull($notification);
        $this->assertTrue($notification->matches(Reference::of('REF7896543'), Amount::fromMinor(6273, 'EUR')));
        $this->assertSame("version=2\ncdr=0\n", $response->getContent());
    }
}
