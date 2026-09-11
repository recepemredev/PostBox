<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Enums\AttemptOutcome;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;

/**
 * The one implementation of HttpTransport. Guzzle's curl handler is what
 * makes CURLOPT_RESOLVE available — the mechanism the pinned GuardedTarget
 * actually relies on — so this is a curl-specific class, not a
 * handler-agnostic wrapper pretending it could be anything else.
 *
 * http_errors is disabled: a 4xx or 5xx is still a response, and whether it
 * counts as a success is AttemptDelivery's decision, not an exception this
 * class would otherwise have to unwrap. Everything caught here is instead a
 * transport failure — the connection itself did not produce a response —
 * classified by the underlying curl error number rather than by which Guzzle
 * exception class wrapped it. ConnectException and RequestException are
 * siblings, both under TransferException, not one a subtype of the other:
 * Guzzle wraps only a fixed subset of connection errno values in
 * ConnectException and leaves the rest as a plain RequestException, and both
 * are caught here for the same reason — reading errno directly is correct
 * regardless of which one a given failure arrived as.
 */
final readonly class GuzzleTransport implements HttpTransport
{
    /**
     * @var list<int>
     */
    private const array DnsErrno = [
        CURLE_COULDNT_RESOLVE_HOST,
        CURLE_COULDNT_RESOLVE_PROXY,
    ];

    /**
     * @var list<int>
     */
    private const array TlsErrno = [
        CURLE_SSL_CONNECT_ERROR,
        CURLE_SSL_CERTPROBLEM,
        CURLE_SSL_CIPHER,
        CURLE_SSL_CACERT,
        CURLE_SSL_CACERT_BADFILE,
    ];

    public function __construct(private ClientInterface $client) {}

    public function send(OutboundRequest $request): TransportResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->client->request('POST', $request->url, [
                RequestOptions::HEADERS => $request->headers,
                RequestOptions::BODY => $request->body,
                // PHP's `/` returns an int when both operands divide evenly, and
                // Guzzle's own types want a float here — the cast keeps the
                // option's type from depending on whether the millisecond
                // figure happens to be a round number of seconds.
                RequestOptions::CONNECT_TIMEOUT => $request->connectTimeoutMs / 1000.0,
                RequestOptions::TIMEOUT => $request->timeoutMs / 1000.0,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::CURL => [
                    // The address AddressGuard already validated, not the
                    // hostname — curl never resolves this host itself.
                    CURLOPT_RESOLVE => ["{$request->target->host}:{$request->target->port}:{$request->target->address}"],
                ],
            ]);

            return TransportResult::responded(
                status: $response->getStatusCode(),
                headers: self::flattenHeaders($response->getHeaders()),
                body: (string) $response->getBody(),
                durationMs: self::elapsedMs($startedAt),
            );
        } catch (ConnectException|RequestException $exception) {
            return TransportResult::failed(
                self::classify($exception),
                $exception->getMessage(),
                self::elapsedMs($startedAt),
            );
        }
    }

    /**
     * @return AttemptOutcome::Timeout|AttemptOutcome::DnsError|AttemptOutcome::TlsError|AttemptOutcome::ConnectionError
     */
    private static function classify(ConnectException|RequestException $exception): AttemptOutcome
    {
        $errno = $exception->getHandlerContext()['errno'] ?? null;

        return match (true) {
            $errno === CURLE_OPERATION_TIMEOUTED => AttemptOutcome::Timeout,
            in_array($errno, self::DnsErrno, true) => AttemptOutcome::DnsError,
            in_array($errno, self::TlsErrno, true) => AttemptOutcome::TlsError,
            default => AttemptOutcome::ConnectionError,
        };
    }

    /**
     * @param  array<array<string>>  $headers
     * @return array<string, string>
     */
    private static function flattenHeaders(array $headers): array
    {
        $flattened = [];

        foreach ($headers as $name => $values) {
            $flattened[(string) $name] = implode(', ', $values);
        }

        return $flattened;
    }

    private static function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
