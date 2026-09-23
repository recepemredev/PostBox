<?php

declare(strict_types=1);

namespace PostBox;

use Closure;
use JsonException;
use PostBox\Exception\AuthenticationFailed;
use PostBox\Exception\IdempotencyConflict;
use PostBox\Exception\NotFound;
use PostBox\Exception\PostBoxException;
use PostBox\Exception\QuotaExhausted;
use PostBox\Exception\RateLimited;
use PostBox\Exception\ServerError;
use PostBox\Exception\TransportFailed;
use PostBox\Exception\UnexpectedResponse;
use PostBox\Exception\ValidationFailed;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Publishes events to PostBox's ingest endpoint:
 * `POST {baseUrl}/v1/apps/{applicationId}/messages`, `Authorization: Bearer
 * {apiKey}`. The HTTP client and its request/stream factories are the two
 * pre-approved boundary interfaces' sibling here — PSR-18 and PSR-17 rather
 * than an HTTP client this package would otherwise have to pick and pin.
 *
 * A request that failed for a reason the same Idempotency-Key makes safe to
 * repeat — a transport failure, a 5xx, or a 429 (honouring Retry-After when
 * the server gives one) — is retried, up to RetryPolicy's own schedule, with
 * that same key. A 401/402/404/409/422 is never retried: retrying would
 * either never fix it or, for 409, actively misuse the key.
 */
final readonly class PostBox
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private RetryPolicy $retryPolicy = new RetryPolicy,
        /** @var (Closure(int): void)|null */
        private ?Closure $sleep = null,
    ) {}

    /**
     * @param  array<mixed>  $payload  A non-empty JSON object or array.
     *
     * @throws PostBoxException
     */
    public function publish(
        string $applicationId,
        string $eventType,
        array $payload,
        ?string $idempotencyKey = null,
    ): Message {
        $key = $idempotencyKey ?? self::generateIdempotencyKey();
        $body = self::encode($eventType, $payload);

        $attempt = 1;

        while (true) {
            try {
                $response = $this->send($applicationId, $key, $body);
            } catch (ClientExceptionInterface $exception) {
                if ($attempt >= $this->retryPolicy->maxAttempts()) {
                    throw new TransportFailed($exception->getMessage(), $exception);
                }

                $this->wait($this->retryPolicy->delayMs($attempt));
                $attempt++;

                continue;
            }

            $status = $response->getStatusCode();

            if ($status === 200 || $status === 201) {
                return Message::fromResponseBody((string) $response->getBody(), $status === 200);
            }

            if (! $this->retryPolicy->isRetryable($status) || $attempt >= $this->retryPolicy->maxAttempts()) {
                throw self::exceptionFor($response);
            }

            $this->wait($this->retryPolicy->delayMs($attempt, self::retryAfterSeconds($response)));
            $attempt++;
        }
    }

    /**
     * @param  array<mixed>  $payload
     *
     * @throws JsonException
     */
    private static function encode(string $eventType, array $payload): string
    {
        return json_encode(['event_type' => $eventType, 'payload' => $payload], JSON_THROW_ON_ERROR);
    }

    /**
     * @throws ClientExceptionInterface
     */
    private function send(string $applicationId, string $idempotencyKey, string $body): ResponseInterface
    {
        $url = rtrim($this->baseUrl, '/')."/v1/apps/{$applicationId}/messages";

        $request = $this->requestFactory->createRequest('POST', $url);
        $request = $request->withHeader('Authorization', "Bearer {$this->apiKey}");
        $request = $request->withHeader('Content-Type', 'application/json');
        $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        $request = $request->withBody($this->streamFactory->createStream($body));

        return $this->httpClient->sendRequest($request);
    }

    private function wait(int $milliseconds): void
    {
        if ($this->sleep !== null) {
            ($this->sleep)($milliseconds);

            return;
        }

        usleep($milliseconds * 1_000);
    }

    private static function generateIdempotencyKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');
        $seconds = filter_var($header, FILTER_VALIDATE_INT);

        return $seconds === false ? null : $seconds;
    }

    private static function exceptionFor(ResponseInterface $response): PostBoxException
    {
        $status = $response->getStatusCode();
        $body = self::decodeBody((string) $response->getBody());
        $message = is_string($body['message'] ?? null) ? $body['message'] : "PostBox answered HTTP {$status}.";
        $headers = self::flattenHeaders($response);

        return match (true) {
            $status === 401 => new AuthenticationFailed($message, $headers),
            $status === 402 => new QuotaExhausted($message, $headers),
            $status === 404 => new NotFound($message, $headers),
            $status === 409 => new IdempotencyConflict($message, $headers),
            $status === 422 => new ValidationFailed($message, self::errorsFrom($body), $headers),
            $status === 429 => new RateLimited($message, self::retryAfterSeconds($response), $headers),
            $status >= 500 => new ServerError($message, $status, $headers),
            default => new UnexpectedResponse($message, $status, $headers),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeBody(string $body): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        // Every body this SDK decodes is `{"message": string, ...}` — a JSON
        // object, never a list — so its keys are always strings in practice;
        // asserted here rather than filtered, the same way generatedContract()
        // does on the backend for the same json_decode(associative: true) shape.
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, list<string>>
     */
    private static function errorsFrom(array $body): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    private static function flattenHeaders(ResponseInterface $response): array
    {
        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        return $headers;
    }
}
