<?php

declare(strict_types=1);

namespace App\Support\Stream;

/**
 * Frames and flushes Server-Sent Events on the wire. One responsibility: it
 * does not know what a delivery attempt is, and it does not track the
 * connection's own deadline — that stays with StreamController, which is
 * the only thing that owns the loop, the sleep and the deadline.
 *
 * response()->eventStream() is not used here: Illuminate\Http\StreamedEvent
 * carries only event and data, with no id: field, so a browser served
 * through it never populates Last-Event-ID and this step's own reconnect
 * requirement cannot be built on it (D127).
 */
final class SseWriter
{
    private bool $opened = false;

    public function __construct(int $maxLifetimeSeconds)
    {
        // FPM's default max_execution_time is 30s and neither php.dev.ini nor
        // php.prod.ini sets it, so a stream at that same default would sit
        // exactly on the edge. Bound explicitly, from config, never 0 — an
        // unbounded script is the thing bounded lifetime exists to avoid.
        set_time_limit($maxLifetimeSeconds + 5);

        // PHP only learns a client disconnected by trying to write and
        // checking connection_aborted() afterwards; without this, the
        // script is torn down mid-write instead, and the finally block
        // that releases the slot never runs.
        ignore_user_abort(true);
    }

    /**
     * The initial retry: line, so the browser's own EventSource reconnects
     * with Last-Event-ID once this connection closes. Written once, before
     * the first event frame.
     */
    public function open(int $reconnectDelayMs): void
    {
        if ($this->opened) {
            return;
        }

        $this->opened = true;
        $this->write("retry: {$reconnectDelayMs}\n\n");
    }

    /**
     * One event frame. $data must already be a single line with no raw
     * newline — the caller JSON-encodes it, which guarantees that.
     */
    public function event(string $id, string $event, string $data): void
    {
        $this->write("id: {$id}\nevent: {$event}\ndata: {$data}\n\n");
    }

    /** A comment line, so an idle connection never looks like a dead socket to a proxy in between. */
    public function heartbeat(): void
    {
        $this->write(": heartbeat\n\n");
    }

    /** Whether the client has disconnected. PHP only learns this by writing, so this is checked after every write. */
    public function aborted(): bool
    {
        return connection_aborted() === 1;
    }

    /**
     * ob_flush(), not ob_end_flush(): the latter also closes the buffer
     * level, which would tear down a caller's own output-capturing buffer
     * (Laravel's own TestResponse::streamedContent() sets one up) rather
     * than push a chunk through it. Mirrors response()->eventStream()'s own
     * low-level mechanics — the one part of that helper worth keeping (D127).
     */
    private function write(string $data): void
    {
        echo $data;

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
