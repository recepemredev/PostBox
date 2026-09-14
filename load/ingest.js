/**
 * Phases 2 through 5 — the ingest surface.
 *
 * One script, not four. The four phases drive the same endpoint with the same
 * shape of load and differ only in the environment around it: whether the
 * delivery workers are running (Phase 2 stops them), how many there are
 * (Phase 4 scales them), and how the sink is configured (Phase 5 degrades it,
 * through the endpoint's own URL — the sink's settings live in the target the
 * seeder wrote, never here). Four copies of this file would be four things to
 * keep in step for no gain.
 *
 * What this script measures is acceptance: requests per second the ingest
 * surface takes and the latency of taking them. Delivery throughput is not
 * visible from here — a delivery happens after the response — and is read
 * from PostBox's own tables afterwards by load/queries/delivery-throughput.sql.
 *
 * Run:
 *   docker compose -f compose.yaml -f compose.bench.yaml --profile bench \
 *     run --rm -e APP_ID=app_... -e API_KEY=pbk_... k6 run /load/ingest.js
 */
import http from 'k6/http';
import { check } from 'k6';
import { number, payload, rampingOptions, text } from './lib/options.js';

export const options = rampingOptions();

// /v1 is the public ingest API, routed by nginx straight to Laravel's front
// controller with no prefix stripped — the path here is exactly the route
// PublishMessageRequest answers (routes/api.php), never /api/v1.
const url = `${text('POSTBOX_URL', 'http://nginx:8080')}/v1/apps/${text('APP_ID', '')}/messages`;

const body = JSON.stringify({
  event_type: text('EVENT_TYPE', 'bench.event'),
  payload: payload(number('PAYLOAD_BYTES', 1024)),
});

const headers = {
  Authorization: `Bearer ${text('API_KEY', '')}`,
  'Content-Type': 'application/json',
  Accept: 'application/json',
};

/*
 * Every request carries a distinct Idempotency-Key, so every request is a new
 * message rather than a replay of the previous one. A run that reused a key
 * would measure the idempotency short-circuit — a real path, but not the one
 * Phase 2 names — and would produce no deliveries for Phase 3 to count.
 */
const run = `${Date.now().toString(36)}`;

export default function () {
  const response = http.post(url, body, {
    headers: { ...headers, 'Idempotency-Key': `${run}-${__VU}-${__ITER}` },
  });

  check(response, {
    'message accepted': (r) => r.status === 201,
    // Recorded separately: a 429 is Governor working, not the server failing,
    // and a run that hits them is reporting the limiter's ceiling rather than
    // the ingest path's. See the note in benchmarking.md.
    'not rate limited': (r) => r.status !== 429,
  });
}
