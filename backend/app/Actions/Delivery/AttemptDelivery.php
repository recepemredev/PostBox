<?php

declare(strict_types=1);

namespace App\Actions\Delivery;

use App\Actions\Resilience\RecordAttemptOutcome;
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
use App\Support\Resilience\RetryPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * One delivery, attempted once: signed, sent — or refused before a single
 * byte leaves the process — and recorded. Every path through this class ends
 * in exactly one delivery_attempts row; there is no branch that produces
 * none, which is what "an attempt is never silently dropped" means in code.
 *
 * The retry schedule is not here either. A failure asks RetryPolicy what to
 * do and writes down the answer — a moment to try again, or the end of the
 * line and the reason for it. This class owns transport and record-keeping;
 * Resilience owns when and whether, and owns the breaker too — this class
 * hands RecordAttemptOutcome the outcome once it is written and never asks
 * what an endpoint's breaker should do about it (modules.md).
 *
 * @phpstan-import-type AttemptFields from AttemptRecord
 */
final readonly class AttemptDelivery
{
    public function __construct(
        private AddressGuard $guard,
        private Signature $signature,
        private HttpTransport $transport,
        private RetryPolicy $retry,
        private RecordAttemptOutcome $breaker,
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

        $attempt = $this->attempt($delivery, $endpoint, $message, $now, $attemptNumber);

        // Every attempt counts toward the endpoint's breaker, independent of
        // which worker ends up settling this particular delivery below — the
        // row is already written, and the window this reads is the ledger,
        // not something scoped to one delivery's own race.
        $this->breaker->handle($endpoint, $attempt['outcome'], $now);

        $this->settle($delivery, $attempt, $attemptNumber, $now);
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

    /**
     * @return AttemptFields
     */
    private function attempt(
        Delivery $delivery,
        Endpoint $endpoint,
        Message $message,
        CarbonImmutable $now,
        int $attemptNumber,
    ): array {
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
     * @param  AttemptFields  $fields
     * @return AttemptFields
     */
    private function record(Delivery $delivery, Endpoint $endpoint, int $attemptNumber, array $fields): array
    {
        DeliveryAttempt::create([
            ...$fields,
            'delivery_id' => $delivery->id,
            'endpoint_id' => $endpoint->id,
            'attempt_number' => $attemptNumber,
        ]);

        return $fields;
    }

    /**
     * Writes down what the attempt means for the delivery as a whole: delivered,
     * due again at a moment the policy chose, or dead-lettered.
     *
     * Every write here is conditional on the delivery still being pending, and
     * that is not belt and braces. reserveAttemptNumber() locks only long enough
     * to hand out a number, so two workers can legitimately hold attempts 1 and
     * 2 of the same delivery at once — a slow send and a lease that expired
     * underneath it. Without the predicate, the slower of the two would settle
     * second and could reopen a delivery the faster one had already exhausted,
     * or dead-letter one that had just succeeded. With it, the first to settle
     * wins and the second writes nothing, which is what makes a message reach
     * the dead letter queue exactly once.
     *
     * @param  AttemptFields  $fields
     */
    private function settle(Delivery $delivery, array $fields, int $attemptNumber, CarbonImmutable $now): void
    {
        if ($fields['outcome'] === AttemptOutcome::Succeeded) {
            $this->settleTo($delivery, [
                'status' => DeliveryStatus::Succeeded,
                'next_attempt_at' => null,
                'last_attempted_at' => $now,
            ]);

            return;
        }

        $decision = $this->retry->decide($fields['outcome'], $fields['response_status'], $attemptNumber, $now);

        if ($decision->shouldRetry) {
            $this->settleTo($delivery, [
                'next_attempt_at' => $decision->nextAttemptAt,
                'last_attempted_at' => $now,
            ]);

            return;
        }

        $this->settleTo($delivery, [
            'status' => DeliveryStatus::Exhausted,
            'next_attempt_at' => null,
            'last_attempted_at' => $now,
            'exhausted_at' => $now,
            'failure_reason' => $decision->reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function settleTo(Delivery $delivery, array $attributes): void
    {
        Delivery::query()
            ->whereKey($delivery->getKey())
            ->where('status', DeliveryStatus::Pending)
            ->update($attributes);
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
