/**
 * Phase 1 — the sink ceiling.
 *
 * k6 drives the sink directly over the bench network, with PostBox entirely
 * out of the path. Every figure any later phase publishes is reported next to
 * the number this script produces, and a PostBox figure at or above 80% of it
 * is reported as sink-bound rather than claimed (benchmarking.md).
 *
 * Run:
 *   docker compose -f compose.yaml -f compose.bench.yaml --profile bench \
 *     run --rm k6 run /load/sink-ceiling.js
 */
import http from 'k6/http';
import { check } from 'k6';
import { number, payload, rampingOptions, text } from './lib/options.js';

export const options = rampingOptions();

const url = `${text('SINK_URL', 'http://sink:8000')}/sink?delay=0&fail_rate=0`;
const body = JSON.stringify(payload(number('PAYLOAD_BYTES', 1024)));
const params = { headers: { 'Content-Type': 'application/json' } };

export default function () {
  const response = http.post(url, body, params);

  check(response, {
    'sink answered 200': (r) => r.status === 200,
  });
}
