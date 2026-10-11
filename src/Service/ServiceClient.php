<?php

declare(strict_types=1);

namespace Piston\Monetico\Service;

use DateTimeZone;
use Piston\Monetico\Configuration\Credentials;
use Piston\Monetico\Configuration\Environment;
use Piston\Monetico\Exception\ServiceFailed;
use Piston\Monetico\Security\AlphabeticalSeal;
use Piston\Monetico\Security\SealAlgorithm;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The two server-to-server services, over PSR-18.
 *
 * PSR-18 rather than a bundled HTTP client so that this library adds no
 * transport, no retry policy and no logging of its own to an application that
 * already has all three. Guzzle, Symfony's HttpClient through its `Psr18Client`
 * and anything else implementing the interface all work; a fake implementation
 * is what the tests of this class use, which is the other half of the reason.
 *
 * Both services are sealed exactly like a payment form - same algorithm, same
 * string - which is why there is one `post()` here and not two.
 */
final class ServiceClient
{
    public function __construct(
        private readonly Credentials $credentials,
        private readonly Environment $environment,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly SealAlgorithm $seal = new AlphabeticalSeal,
        private readonly ?DateTimeZone $timezone = null,
    ) {}

    public function capture(CaptureRequest $request): CaptureResult
    {
        return CaptureResult::from($this->post(
            $this->environment->captureUrl(),
            $request->fields($this->credentials, $this->timezone),
        ));
    }

    public function refund(RefundRequest $request): RefundResult
    {
        return RefundResult::from($this->post(
            $this->environment->refundUrl(),
            $request->fields($this->credentials, $this->timezone),
        ));
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function post(string $url, array $fields): ServiceReply
    {
        $fields['MAC'] = $this->seal->seal($fields, $this->credentials->key)->value();

        // RFC 3986, not RFC 1738: the difference is that a space becomes %20
        // instead of +, and the documented examples percent-encode everything
        // including the slashes and colons of the dates.
        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'text/plain')
            ->withBody($this->streams->createStream($body));

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw ServiceFailed::transport($e);
        }

        $contents = (string) $response->getBody();

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw ServiceFailed::status($response->getStatusCode(), $contents);
        }

        return ServiceReply::parse($contents);
    }
}
