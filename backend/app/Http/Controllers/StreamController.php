<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Stream\StreamAttempts;
use App\Exceptions\StreamSlotExceeded;
use App\Http\Requests\StreamRequest;
use App\Http\Resources\StreamedAttemptResource;
use App\Support\Pagination\KeysetCursor;
use App\Support\Stream\SseWriter;
use App\Support\Stream\StreamSlots;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The live SSE stream. It owns the loop, the sleep and the deadline, and
 * nothing else — domain logic lives in StreamAttempts, slot admission in
 * StreamSlots, wire framing in SseWriter (Controller -> Action -> Resource,
 * with the streaming response itself standing in for a plain return).
 */
final class StreamController extends Controller
{
    public function __invoke(
        StreamRequest $request,
        StreamAttempts $attempts,
        StreamSlots $slots,
        TenantContext $context,
    ): StreamedResponse {
        $tenant = $context->currentOrFail();
        $connectionId = (string) Str::ulid();
        $now = CarbonImmutable::now();
        $maxLifetimeSeconds = Config::integer('postbox.stream.max_lifetime_seconds');
        $deadline = $now->addSeconds($maxLifetimeSeconds);

        if (! $slots->admit($tenant, $connectionId, $now)) {
            throw new StreamSlotExceeded((int) ceil($maxLifetimeSeconds / 2));
        }

        return response()->stream(
            function () use ($request, $attempts, $slots, $tenant, $connectionId, $deadline, $maxLifetimeSeconds): void {
                try {
                    $this->run($request, $attempts, new SseWriter($maxLifetimeSeconds), $deadline);
                } finally {
                    $slots->release($tenant, $connectionId);
                }
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function run(StreamRequest $request, StreamAttempts $attempts, SseWriter $writer, CarbonImmutable $deadline): void
    {
        $writer->open(Config::integer('postbox.stream.reconnect_delay_ms'));

        $pollIntervalMicroseconds = Config::integer('postbox.stream.poll_interval_ms') * 1000;
        $heartbeatSeconds = Config::integer('postbox.stream.heartbeat_seconds');

        $cursor = $request->cursor();
        $lastHeartbeat = CarbonImmutable::now();

        while (CarbonImmutable::now()->lt($deadline)) {
            if ($writer->aborted()) {
                return;
            }

            $page = $attempts->poll($cursor, CarbonImmutable::now());

            foreach ($page->items as $attempt) {
                if ($writer->aborted()) {
                    return;
                }

                // The cursor advances per emitted row, not per batch, so an
                // abort mid-batch still resumes from exactly where the
                // client actually stopped, never from the batch's own start.
                $cursor = KeysetCursor::after($attempt->created_at, $attempt->public_id);

                $writer->event(
                    id: $cursor->encode(),
                    event: 'attempt',
                    data: json_encode(StreamedAttemptResource::make($attempt)->resolve(), JSON_THROW_ON_ERROR),
                );
            }

            if ($writer->aborted()) {
                return;
            }

            $now = CarbonImmutable::now();

            // A raw Unix-timestamp subtraction, not diffInSeconds(): Carbon's
            // own diffInSeconds($other) measures $other relative to $this,
            // not the other way around, which is exactly the sign mistake
            // Signature::verify() already avoids the same way for its own
            // tolerance window.
            $idleSeconds = abs($now->getTimestamp() - $lastHeartbeat->getTimestamp());

            if ($page->items->isEmpty() && $idleSeconds >= $heartbeatSeconds) {
                $writer->heartbeat();
                $lastHeartbeat = $now;
            }

            if ($writer->aborted()) {
                return;
            }

            usleep($pollIntervalMicroseconds);
        }
    }
}
