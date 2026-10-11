<?php

declare(strict_types=1);

namespace Piston\Monetico;

use DateTimeZone;
use Piston\Monetico\Configuration\Credentials;
use Piston\Monetico\Configuration\Environment;
use Piston\Monetico\Exception\MissingHttpClient;
use Piston\Monetico\Notification\Notification;
use Piston\Monetico\Notification\NotificationVerifier;
use Piston\Monetico\Payment\PaymentForm;
use Piston\Monetico\Payment\PaymentRequest;
use Piston\Monetico\Security\AlphabeticalSeal;
use Piston\Monetico\Security\SealVerifier;
use Piston\Monetico\Service\CaptureRequest;
use Piston\Monetico\Service\CaptureResult;
use Piston\Monetico\Service\RefundRequest;
use Piston\Monetico\Service\RefundResult;
use Piston\Monetico\Service\ServiceClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * The whole library in one object: seal a form, verify a notification,
 * capture, refund.
 *
 * Four verbs, because the protocol has four. Everything else in this package
 * is a value object those four take or return, and nothing holds state between
 * calls - so one instance per shop, built once, is the intended shape, and it
 * is safe to keep in a container.
 *
 * The HTTP client is optional on purpose: a shop that only takes payments
 * never needs one, and asking for it in the constructor would make every
 * integration install a transport to render a form.
 */
final class Monetico
{
    private readonly SealVerifier $seals;

    public function __construct(
        public readonly Credentials $credentials,
        public readonly Environment $environment = Environment::Sandbox,
        private readonly ?ClientInterface $http = null,
        private readonly ?RequestFactoryInterface $requests = null,
        private readonly ?StreamFactoryInterface $streams = null,
        ?SealVerifier $seals = null,
        private readonly ?DateTimeZone $timezone = null,
    ) {
        $this->seals = $seals ?? new SealVerifier;
    }

    public static function sandbox(
        string $terminal,
        #[SensitiveParameter] string $key,
        string $company,
    ): self {
        return new self(Credentials::fromStrings($terminal, $key, $company), Environment::Sandbox);
    }

    public static function production(
        string $terminal,
        #[SensitiveParameter] string $key,
        string $company,
    ): self {
        return new self(Credentials::fromStrings($terminal, $key, $company), Environment::Production);
    }

    /** The same shop, against the other environment. */
    public function using(Environment $environment): self
    {
        return new self(
            $this->credentials,
            $environment,
            $this->http,
            $this->requests,
            $this->streams,
            $this->seals,
            $this->timezone,
        );
    }

    /**
     * Seal a payment request into a form.
     *
     * The order of operations is the whole job: build the fields, seal them,
     * then - and only then - escape them for wherever they are going. See
     * PaymentRequest::fields() and PaymentForm.
     */
    public function form(PaymentRequest $request): PaymentForm
    {
        $fields = $request->fields($this->credentials, $this->timezone);
        $fields['MAC'] = (new AlphabeticalSeal)->seal($fields, $this->credentials->key)->value();

        return new PaymentForm($fields, $this->environment->paymentUrl());
    }

    public function verifier(): NotificationVerifier
    {
        return new NotificationVerifier($this->credentials, $this->seals);
    }

    /**
     * Verify an incoming notification, or throw.
     *
     * @param  array<string, mixed>  $post
     *
     * @throws Exception\SealMismatch
     */
    public function notification(array $post): Notification
    {
        return $this->verifier()->verify($post);
    }

    /**
     * Verify an incoming notification, or get null.
     *
     * @param  array<string, mixed>  $post
     */
    public function attemptNotification(array $post): ?Notification
    {
        return $this->verifier()->attempt($post);
    }

    public function capture(CaptureRequest $request): CaptureResult
    {
        return $this->services()->capture($request);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        return $this->services()->refund($request);
    }

    public function services(): ServiceClient
    {
        $http = $this->http ?? Discovery::client();
        $requests = $this->requests ?? Discovery::requestFactory();
        $streams = $this->streams ?? Discovery::streamFactory();

        if ($http === null || $requests === null || $streams === null) {
            throw MissingHttpClient::create();
        }

        return new ServiceClient(
            $this->credentials,
            $this->environment,
            $http,
            $requests,
            $streams,
            new AlphabeticalSeal,
            $this->timezone,
        );
    }
}
