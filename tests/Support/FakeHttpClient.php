<?php

declare(strict_types=1);

namespace Piston\Monetico\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from a queue and keeps what it was asked.
 *
 * The capture and refund services are not reachable from a test suite -
 * production takes real money and the sandbox needs a contract, an
 * allow-listed IP address and a daily quota - so what is tested here is
 * everything up to the socket: the URL, the method, the headers, the encoding
 * of the body and the seal inside it, against the request examples printed in
 * the platform's own documentation.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    public static function replying(string $body, int $status = 200): self
    {
        $client = new self;
        $client->queue[] = new Response($status, ['Content-Type' => 'text/plain'], $body);

        return $client;
    }

    public static function failing(ClientExceptionInterface $exception): self
    {
        $client = new self;
        $client->queue[] = $exception;

        return $client;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next ?? new Response(200, [], "version=1.0\ncdr=1\nlib=paiement accepte\n");
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[count($this->requests) - 1];
    }

    /**
     * The body, decoded back into fields.
     *
     * @return array<string, string>
     */
    public function lastBodyFields(): array
    {
        $fields = [];
        parse_str((string) $this->lastRequest()->getBody(), $fields);

        /** @var array<string, string> $fields */
        return $fields;
    }
}
