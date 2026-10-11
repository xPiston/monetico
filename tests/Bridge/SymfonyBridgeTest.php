<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Bridge;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Bridge\Symfony\AcknowledgementResponse;
use Piston\Monetico\Bridge\Symfony\MoneticoBundle;
use Piston\Monetico\Bridge\Symfony\NotificationRequest;
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
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Symfony bundle, compiled by Symfony's own container.
 *
 * The extension is loaded and the container is compiled for real, so a service
 * definition with an argument that cannot be resolved fails here rather than
 * on the first request of an application that installed the bundle.
 */
final class SymfonyBridgeTest extends TestCase
{
    public function test_it_builds_a_configured_client(): void
    {
        $monetico = $this->monetico();

        $this->assertInstanceOf(Monetico::class, $monetico);
        $this->assertSame(Fixtures::TERMINAL, $monetico->credentials->terminal->value());
        $this->assertSame(Fixtures::COMPANY, $monetico->credentials->company->value());
        $this->assertSame(Environment::Sandbox, $monetico->environment);
    }

    public function test_the_same_instance_is_shared(): void
    {
        $container = $this->container();

        $this->assertSame($container->get(Monetico::class), $container->get(Monetico::class));
        $this->assertNotNull($container->get(Monetico::class));
    }

    public function test_the_verifier_is_a_service_of_its_own(): void
    {
        $verifier = $this->verifier();

        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF001', '10.00EUR');

        $this->assertTrue($verifier->verify($post)->isAccepted());
    }

    public function test_production_is_a_configuration_choice(): void
    {
        $monetico = $this->monetico(['environment' => 'production']);

        $this->assertSame(Environment::Production, $monetico->environment);
        $this->assertSame('https://p.monetico-services.com/paiement.cgi', $monetico->environment->paymentUrl());
    }

    public function test_an_environment_that_is_neither_is_refused_by_the_configuration_tree(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->container(['environment' => 'staging']);
    }

    public function test_the_terminal_is_required(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->container(['terminal' => null]);
    }

    public function test_an_empty_key_is_refused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->container(['key' => '']);
    }

    public function test_an_impossible_terminal_is_refused_when_the_service_is_built(): void
    {
        $this->expectException(\Piston\Monetico\Exception\InvalidField::class);

        $this->monetico(['terminal' => 'much too long']);
    }

    public function test_the_legacy_seal_can_be_switched_off(): void
    {
        $post = FakePlatform::legacy(Fixtures::credentials())->acceptedPayment('REF001', '10.00EUR');

        $this->assertTrue($this->monetico()->notification($post)->isAccepted());

        $this->expectException(SealMismatch::class);

        $this->monetico(['accept_legacy_seals' => false])->notification($post);
    }

    public function test_the_configured_timezone_is_the_one_the_dates_are_sent_in(): void
    {
        $form = $this->monetico(['timezone' => 'UTC'])->form(new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: new DateTimeImmutable('2026-06-24 08:00:25', new DateTimeZone('UTC')),
        ));

        $this->assertSame('24/06/2026:08:00:25', $form->fields()['date']);
    }

    public function test_the_default_timezone_is_the_platform_one(): void
    {
        $form = $this->monetico()->form(new PaymentRequest(
            reference: Reference::of('REF001'),
            amount: Amount::fromMinor(100, 'EUR'),
            orderedAt: new DateTimeImmutable('2026-06-24 08:00:25', new DateTimeZone('UTC')),
        ));

        $this->assertSame('24/06/2026:10:00:25', $form->fields()['date']);
    }

    public function test_the_extension_is_called_monetico(): void
    {
        $this->assertSame('monetico', (new MoneticoBundle)->getContainerExtension()?->getAlias());
    }

    /**
     * `$request->request->all()` and not `$request->get()`: a query parameter
     * on the notification route would otherwise join the fields the seal is
     * computed over.
     */
    public function test_the_notification_fields_come_from_the_body_only(): void
    {
        $post = FakePlatform::for(Fixtures::credentials())->acceptedPayment('REF001', '10.00EUR');
        $request = Request::create('/monetico/notify?shop=42', 'POST', $post);

        $fields = NotificationRequest::fields($request);

        $this->assertArrayNotHasKey('shop', $fields);
        $this->assertTrue($this->monetico()->notification($fields)->isAccepted());
    }

    public function test_the_acknowledgement_is_plain_text_with_a_two_hundred(): void
    {
        $response = AcknowledgementResponse::for(Acknowledgement::accepted());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame("version=2\ncdr=0\n", $response->getContent());
    }

    public function test_a_rejected_acknowledgement_is_also_a_two_hundred(): void
    {
        $response = AcknowledgementResponse::for(Acknowledgement::rejected());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame("version=2\ncdr=1\n", $response->getContent());
    }

    /** @param array<string, mixed> $overrides */
    private function monetico(array $overrides = []): Monetico
    {
        $service = $this->container($overrides)->get(Monetico::class);

        $this->assertInstanceOf(Monetico::class, $service);

        return $service;
    }

    private function verifier(): NotificationVerifier
    {
        $service = $this->container()->get(NotificationVerifier::class);

        $this->assertInstanceOf(NotificationVerifier::class, $service);

        return $service;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function container(array $overrides = []): ContainerBuilder
    {
        $config = [
            'terminal' => Fixtures::TERMINAL,
            'key' => Fixtures::KEY,
            'company' => Fixtures::COMPANY,
            ...$overrides,
        ];

        foreach ($config as $name => $value) {
            if ($value === null) {
                unset($config[$name]);
            }
        }

        // The parameters any Symfony kernel has when a bundle is loaded; an
        // AbstractBundle extension reads them while building its definitions.
        $builder = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.debug' => false,
            'kernel.project_dir' => dirname(__DIR__, 2),
            'kernel.build_dir' => sys_get_temp_dir(),
            'kernel.cache_dir' => sys_get_temp_dir(),
        ]));
        $extension = (new MoneticoBundle)->getContainerExtension();

        $this->assertNotNull($extension);

        $extension->load([$config], $builder);

        // Private by default, like any Symfony service; made public here so
        // the test can reach in the way an autowired controller would.
        foreach ([Monetico::class, NotificationVerifier::class] as $service) {
            $builder->getDefinition($service)->setPublic(true);
        }

        $builder->compile();

        return $builder;
    }
}
