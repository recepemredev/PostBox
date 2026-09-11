<?php

declare(strict_types=1);

namespace App\Actions\Delivery;

use App\Enums\AttemptOutcome;
use App\Enums\DeliveryStatus;
use App\Exceptions\BlockedTarget;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use App\Models\EndpointSecret;
use App\Models\Message;
use App\Support\Delivery\AddressGuard;
use App\Support\Delivery\AttemptRecord;
use App\Support\Delivery\HttpTransport;
use App\Support\Delivery\OutboundRequest;
use App\Support\Delivery\Signature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * One delivery, attempted once: signed, sent — or refused before a single
 * byte leaves the process — and recorded. Every path through this class ends
 * in exactly one delivery_attempts row; there is no branch that produces
 * none, which is what "an attempt is never silently dropped" means in code.
 *
 * There is no retry schedule here. A failure leaves the delivery pending and
 * touches nothing about when it is due again — the dispatcher's own lease,
 * already pushed forward when this job was claimed, is what makes it due
 * once more. Step 7 replaces that with a real backoff; until then the lease
 * interval is the only one there is, and this class does not pretend
 * otherwise by computing a delay it has nowhere to act on.
 */
final readonly class AttemptDelivery
{
    public function __construct(
        private AddressGuard $guard,
        private Signature $signature,
        private HttpTransport $transport,
    ) {}

    public function handle(Delivery $delivery, CarbonImmutable $now): void
    {
        $attemptNumber = $this->reserveAttemptNumber($delivery);

        if ($attemptNumber === null) {
            // Already settled by another worker — safe to run twice.
            return;
        }

        $endpoint = $delivery->endpoint;
        $message = Message::query()->with('eventType')->findOrFail($delivery->message_id);

        $outcome = $this->attempt($delivery, $endpoint, $message, $now, $attemptNumber);

        $this->settle($delivery, $outcome, $now);
    }

    /**
     * Atomically claims the next attempt number, or null if another worker
     * already moved this delivery out of Pending. The lock is held only for
     * this — never across the network call that follows — so a slow send
     * never blocks the dispatcher's own claim query.
     */
    private function reserveAttemptNumber(Delivery $delivery): ?int
    {
        return DB::transaction(function () use ($delivery): ?int {
            $locked = Delivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== DeliveryStatus::Pending) {
                return null;
            }

            $locked->increment('attempt_count');

            return $locked->attempt_count;
        });
    }

    private function attempt(
        Delivery $delivery,
        Endpoint $endpoint,
        Message $message,
        CarbonImmutable $now,
        int $attemptNumber,
    ): AttemptOutcome {
        $payload = self::encode($message->payload);

        $headers = [
            'Content-Type' => 'application/json',
            'PostBox-Message-Id' => $message->public_id,
            'PostBox-Event-Type' => $message->eventType->name,
            'PostBox-Timestamp' => (string) $now->getTimestamp(),
        ];

        $secrets = self::activeSecrets($endpoint);

        if ($secrets === []) {
            return $this->record($delivery, $endpoint, $attemptNumber, AttemptRecord::blocked(
                $headers,
                $payload,
                'The endpoint has no active signing secret.',
                durationMs: 0,
            ));
        }

        $headers['PostBox-Signature'] = $this->signature->sign($payload, $now, $secrets);

        try {
            $target = $this->guard->guard($endpoint->url);
        } catch (BlockedTarget $refusal) {
            return $this->record($delivery, $endpoint, $attemptNumber, AttemptRecord::blocked(
                $headers,
                $payload,
                $refusal->getMessage(),
                durationMs: 0,
            ));
        }

        $result = $this->transport->send(new OutboundRequest(
            url: $endpoint->url,
            target: $target,
            headers: $headers,
            body: $payload,
            connectTimeoutMs: Config::integer('postbox.delivery.connect_timeout_ms'),
            timeoutMs: Config::integer('postbox.delivery.timeout_ms'),
        ));

        return $this->record($delivery, $endpoint, $attemptNumber, AttemptRecord::fromResponse($headers, $payload, $result));
    }

    /**
     * @param  array{outcome: AttemptOutcome, request_headers: array<string, string>, request_body: string, response_status: int|null, response_headers: array<string, string>|null, response_body: string|null, error_message: string|null, duration_ms: int}  $fields
     */
    private function record(Delivery $delivery, Endpoint $endpoint, int $attemptNumber, array $fields): AttemptOutcome
    {
        DeliveryAttempt::create([
            ...$fields,
            'delivery_id' => $delivery->id,
            'endpoint_id' => $endpoint->id,
            'attempt_number' => $attemptNumber,
        ]);

        return $fields['outcome'];
    }

    private function settle(Delivery $delivery, AttemptOutcome $outcome, CarbonImmutable $now): void
    {
        if ($outcome === AttemptOutcome::Succeeded) {
            $delivery->update([
                'status' => DeliveryStatus::Succeeded,
                'next_attempt_at' => null,
                'last_attempted_at' => $now,
            ]);

            return;
        }

        $delivery->update(['last_attempted_at' => $now]);
    }

    /**
     * The plaintext of every secret this endpoint currently holds live — more
     * than one during rotation. The encrypted cast decrypts on read regardless
     * of the column being $hidden, which only governs JSON serialization.
     *
     * @return list<string>
     */
    private static function activeSecrets(Endpoint $endpoint): array
    {
        $secrets = [];

        foreach (EndpointSecret::query()->where('endpoint_id', $endpoint->id)->current()->get() as $secret) {
            if (is_string($secret->secret)) {
                $secrets[] = $secret->secret;
            }
        }

        return $secrets;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function encode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // The payload was accepted as valid JSON at ingest (Step 4) and is
        // opaque to PostBox from then on — encoding it back can only fail if
        // that were no longer true.
        assert(is_string($encoded));

        return $encoded;
    }
}
