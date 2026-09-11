<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Enums\AttemptOutcome;
use Illuminate\Support\Facades\Config;

/**
 * The fields one delivery_attempts row is built from — scrubbed and
 * size-capped the same way for a request as for a response, because a
 * secret or an oversized body reaching storage is exactly as much a defect
 * coming from PostBox's own outgoing headers as from an endpoint's reply.
 *
 * delivery_id, endpoint_id and attempt_number are not this class's concern:
 * they come from the database numbering AttemptDelivery reserves, not from
 * anything a response or a refusal carries.
 */
final readonly class AttemptRecord
{
    /**
     * @param  array<string, string>  $requestHeaders
     * @return array{outcome: AttemptOutcome, request_headers: array<string, string>, request_body: string, response_status: int|null, response_headers: array<string, string>|null, response_body: string|null, error_message: string|null, duration_ms: int}
     */
    public static function fromResponse(array $requestHeaders, string $requestBody, TransportResult $result): array
    {
        if ($result->responded) {
            /** @var int $status */
            $status = $result->status;
            /** @var array<string, string> $headers */
            $headers = $result->headers;
            /** @var string $body */
            $body = $result->body;

            return [
                'outcome' => self::isSuccessful($status) ? AttemptOutcome::Succeeded : AttemptOutcome::Failed,
                'request_headers' => self::scrub($requestHeaders),
                'request_body' => self::cap($requestBody),
                'response_status' => $status,
                'response_headers' => self::scrub($headers),
                'response_body' => self::cap($body),
                'error_message' => null,
                'duration_ms' => $result->durationMs,
            ];
        }

        /** @var AttemptOutcome $failure */
        $failure = $result->failure;

        return [
            'outcome' => $failure,
            'request_headers' => self::scrub($requestHeaders),
            'request_body' => self::cap($requestBody),
            'response_status' => null,
            'response_headers' => null,
            'response_body' => null,
            'error_message' => $result->errorMessage,
            'duration_ms' => $result->durationMs,
        ];
    }

    /**
     * PostBox refused to send at all — no attempt at a connection, so
     * nothing about a response to record.
     *
     * @param  array<string, string>  $requestHeaders
     * @return array{outcome: AttemptOutcome, request_headers: array<string, string>, request_body: string, response_status: int|null, response_headers: array<string, string>|null, response_body: string|null, error_message: string|null, duration_ms: int}
     */
    public static function blocked(array $requestHeaders, string $requestBody, string $reason, int $durationMs): array
    {
        return [
            'outcome' => AttemptOutcome::Blocked,
            'request_headers' => self::scrub($requestHeaders),
            'request_body' => self::cap($requestBody),
            'response_status' => null,
            'response_headers' => null,
            'response_body' => null,
            'error_message' => $reason,
            'duration_ms' => $durationMs,
        ];
    }

    private static function isSuccessful(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function scrub(array $headers): array
    {
        $scrubbed = [];

        foreach (Config::array('postbox.delivery.scrubbed_headers') as $name) {
            if (is_string($name)) {
                $scrubbed[] = strtolower($name);
            }
        }

        return array_filter(
            $headers,
            static fn (string $name): bool => ! in_array(strtolower($name), $scrubbed, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private static function cap(string $body): string
    {
        $ceiling = Config::integer('postbox.delivery.max_recorded_body_bytes');

        return mb_strcut($body, 0, $ceiling);
    }
}
