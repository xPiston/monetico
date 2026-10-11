<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Piston\Monetico\Configuration\Environment;
use Piston\Monetico\Discovery;
use Piston\Monetico\Exception\MissingHttpClient;
use Piston\Monetico\Exception\ServiceFailed;
use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\Reference;
use Piston\Monetico\Monetico;
use Piston\Monetico\Security\AlphabeticalSeal;
use Piston\Monetico\Service\CaptureRequest;
use Piston\Monetico\Service\RefundRequest;
use Piston\Monetico\Service\ServiceClient;
use Piston\Monetico\Tests\Support\FakeHttpClient;
use Piston\Monetico\Tests\Support\Fixtures;
use Piston\Monetico\Tests\Support\TransportFailure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Everything the capture and refund services do up to the socket.
 *
 * The request examples in the documentation are HTTP requests, headers
 * included, so these tests assert the same things a packet capture would: the
 * method, the host and path, the content type, the encoding of the body, and
 * the seal inside it.
 */
final class ServiceClientTest extends TestCase
{
    public function test_a_capture_goes_to_the_capture_service(): void
    {
        $http = FakeHttpClient::replying("version=1.0\nreference=ABERTPY00145\ncdr=1\nlib=paiement accepte\naut=123456");

        $result = $this->monetico($http)->capture($this->capture());

        $this->assertTrue($result->isAccepted());
        $this->assertSame('POST', $http->lastRequest()->getMethod());
        $this->assertSame(
            'https://payment-api.e-i.com/test/capture_paiement.cgi',
            (string) $http->lastRequest()->getUri(),
        );
        $this->assertSame('application/x-www-form-urlencoded', $http->lastRequest()->getHeaderLine('Content-Type'));
    }

    public function test_a_refund_goes_to_the_refund_service(): void
    {
        $http = FakeHttpClient::replying("version=1.0\ncdr=0\nlib=recredit effectue");

        $result = $this->monetico($http)->refund($this->refund());

        $this->assertTrue($result->isCompleted());
        $this->assertSame(
            'https://payment-api.e-i.com/test/recredit_paiement.cgi',
            (string) $http->lastRequest()->getUri(),
        );
    }

    public function test_in_production_they_go_somewhere_else(): void
    {
        $http = FakeHttpClient::replying("cdr=1\nlib=paiement accepte");

        $this->monetico($http, Environment::Production)->capture($this->capture());

        $this->assertSame(
            'https://payment-api.e-i.com/capture_paiement.cgi',
            (string) $http->lastRequest()->getUri(),
        );
    }

    /**
     * The payment form and the two services are not on the same host, which
     * is the sort of thing an integration discovers on the day of its first
     * refund.
     */
    public function test_the_services_are_not_on_the_payment_host(): void
    {
        $this->assertStringContainsString('p.monetico-services.com', Environment::Production->paymentUrl());
        $this->assertStringContainsString('payment-api.e-i.com', Environment::Production->captureUrl());
        $this->assertStringContainsString('payment-api.e-i.com', Environment::Production->refundUrl());
    }

    public function test_the_body_carries_the_documented_fields(): void
    {
        $http = FakeHttpClient::replying("cdr=1\nlib=paiement accepte");

        $this->monetico($http)->capture($this->capture());

        $fields = $http->lastBodyFields();

        $this->assertSame('3.0', $fields['version']);
        $this->assertSame('1234567', $fields['TPE']);
        $this->assertSame('05/12/2006:11:55:23', $fields['date']);
        $this->assertSame('03/12/2006', $fields['date_commande']);
        $this->assertSame('100.00EUR', $fields['montant']);
        $this->assertSame('62.00EUR', $fields['montant_a_capturer']);
        $this->assertSame('38.00EUR', $fields['montant_restant']);
        $this->assertSame('ABERTPY00145', $fields['reference']);
        $this->assertSame('monSite1', $fields['societe']);
        $this->assertSame('FR', $fields['lgue']);
    }

    /**
     * RFC 3986, as the documented examples show: the slashes and colons of a
     * date are percent-encoded, and a space would be `%20` rather than `+`.
     */
    public function test_the_body_is_percent_encoded_the_way_the_examples_are(): void
    {
        $http = FakeHttpClient::replying("cdr=1\nlib=paiement accepte");

        $this->monetico($http)->capture($this->capture());

        $body = (string) $http->lastRequest()->getBody();

        $this->assertStringContainsString('date=05%2F12%2F2006%3A11%3A55%3A23', $body);
        $this->assertStringContainsString('montant=100.00EUR', $body);
        $this->assertStringNotContainsString('+', $body);
    }

    public function test_the_body_is_sealed(): void
    {
        $http = FakeHttpClient::replying("cdr=1\nlib=paiement accepte");

        $this->monetico($http)->capture($this->capture());

        $fields = $http->lastBodyFields();
        $seal = $fields['MAC'];
        unset($fields['MAC']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $seal);
        $this->assertSame(
            (new AlphabeticalSeal)->seal($fields, Fixtures::credentials()->key)->value(),
            $seal,
            'the seal in the body must be the seal of the body',
        );
    }

    public function test_the_seal_is_not_part_of_what_it_seals(): void
    {
        $http = FakeHttpClient::replying("cdr=1\nlib=paiement accepte");

        $this->monetico($http)->capture($this->capture());

        // Computed from the fields the request object builds, which do not
        // include a MAC at all.
        $expected = (new AlphabeticalSeal)
            ->seal($this->capture()->fields(Fixtures::credentials()), Fixtures::credentials()->key)
            ->value();

        $this->assertSame($expected, $http->lastBodyFields()['MAC']);
    }

    public function test_a_transport_failure_is_not_a_refusal(): void
    {
        $http = FakeHttpClient::failing(new TransportFailure('Connection timed out'));

        $this->expectException(ServiceFailed::class);
        $this->expectExceptionMessage('could not be reached: Connection timed out');

        $this->monetico($http)->capture($this->capture());
    }

    public function test_an_http_error_is_not_a_reply(): void
    {
        $http = FakeHttpClient::replying('<html>Gateway Timeout</html>', 504);

        $this->expectException(ServiceFailed::class);
        $this->expectExceptionMessage('answered HTTP 504');

        $this->monetico($http)->capture($this->capture());
    }

    public function test_a_body_that_is_not_a_reply_is_not_read_as_one(): void
    {
        $http = FakeHttpClient::replying('<html>maintenance</html>', 200);

        $this->expectException(ServiceFailed::class);
        $this->expectExceptionMessage('not a "key=value" reply');

        $this->monetico($http)->refund($this->refund());
    }

    /**
     * The payment form needs no transport, so the constructor does not ask for
     * one. When the application has a client anyway - and anything with Guzzle
     * or symfony/http-client does - the services find it without being wired.
     */
    public function test_a_client_the_application_already_has_is_found_on_its_own(): void
    {
        $monetico = new Monetico(Fixtures::credentials());

        $this->assertInstanceOf(ServiceClient::class, $monetico->services());
        $this->assertInstanceOf(ClientInterface::class, Discovery::client());
        $this->assertInstanceOf(RequestFactoryInterface::class, Discovery::requestFactory());
        $this->assertInstanceOf(StreamFactoryInterface::class, Discovery::streamFactory());
    }

    /**
     * And when it does not, the exception is the documentation: a refund that
     * fails on a missing transport is not the moment to go looking for which
     * interface to bind.
     */
    public function test_the_error_for_a_missing_client_says_what_to_install(): void
    {
        $message = MissingHttpClient::create()->getMessage();

        $this->assertStringContainsString('PSR-18', $message);
        $this->assertStringContainsString('php-http/discovery', $message);
        $this->assertStringContainsString('symfony/http-client', $message);
        $this->assertStringContainsString('guzzlehttp/guzzle', $message);
    }

    public function test_the_same_shop_can_be_pointed_at_the_other_environment(): void
    {
        $http = FakeHttpClient::replying("cdr=1\nlib=paiement accepte");

        $monetico = $this->monetico($http)->using(Environment::Production);

        $monetico->capture($this->capture());

        $this->assertStringStartsWith(
            'https://payment-api.e-i.com/capture_paiement.cgi',
            (string) $http->lastRequest()->getUri(),
        );
    }

    private function monetico(FakeHttpClient $http, Environment $environment = Environment::Sandbox): Monetico
    {
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory;

        return new Monetico(Fixtures::credentials(), $environment, $http, $factory, $factory);
    }

    private function capture(): CaptureRequest
    {
        $paris = new DateTimeZone('Europe/Paris');

        return CaptureRequest::of(
            reference: Reference::of('ABERTPY00145'),
            orderAmount: Amount::fromString('100.00EUR'),
            toCapture: Amount::fromString('62.00EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03', $paris),
            alreadyCaptured: Amount::fromString('0EUR'),
            remaining: Amount::fromString('38.00EUR'),
            requestedAt: new DateTimeImmutable('2006-12-05 11:55:23', $paris),
        );
    }

    private function refund(): RefundRequest
    {
        $paris = new DateTimeZone('Europe/Paris');

        return RefundRequest::of(
            reference: Reference::of('ABERTPY00145'),
            orderAmount: Amount::fromString('100.00EUR'),
            toRefund: Amount::fromString('32.00EUR'),
            orderedOn: new DateTimeImmutable('2006-12-03', $paris),
            remittedOn: new DateTimeImmutable('2006-12-04', $paris),
            authorisationNumber: '1234A6',
            refundable: Amount::fromString('100EUR'),
            requestedAt: new DateTimeImmutable('2006-12-05 11:55:23', $paris),
        );
    }
}
