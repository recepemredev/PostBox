<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 test double: answers with whatever was queued, in order, and
 * records every request PostBox::publish() actually sent — so a test can
 * assert the same Idempotency-Key travelled on every retry, or that a
 * non-retryable status never produced a second request at all.
 */
final class FakeClient implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function queueResponse(ResponseInterface $response): void
    {
        $this->queue[] = $response;
    }

    public function queueException(ClientExceptionInterface $exception): void
    {
        $this->queue[] = $exception;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue)
            ?? throw new RuntimeException('FakeClient::sendRequest() called more times than a response was queued.');

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }
}
