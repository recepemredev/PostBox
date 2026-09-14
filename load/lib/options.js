/**
 * One ramp definition, shared by every phase.
 *
 * benchmarking.md ramps each phase until p95 degrades or throughput plateaus,
 * and the phases differ only in what they point at — the shape of the load is
 * the same. Two scripts with their own copies of the stages would drift, and
 * the first figure published from a drifted script is a figure that cannot be
 * compared against the sink ceiling it is reported next to.
 *
 * Every knob comes from the environment so a phase is reproducible from the
 * command that ran it, and the command is recorded with the results.
 */

const read = (name, fallback) => (__ENV[name] !== undefined ? __ENV[name] : fallback);

export const number = (name, fallback) => Number(read(name, fallback));

export const text = (name, fallback) => String(read(name, fallback));

/**
 * `STAGES` is a comma-separated list of `rate:duration` steps, e.g.
 * "100:30s,200:30s,400:30s". Written out rather than computed from a start and
 * a factor: the exact rates a run climbed through belong in the results file,
 * and a literal list is the version of them that cannot be mis-derived later.
 */
function stages() {
  return text('STAGES', '100:30s,200:30s,400:30s,800:30s')
    .split(',')
    .map((step) => {
      const [target, duration] = step.split(':');
      return { target: Number(target), duration };
    });
}

export function rampingOptions() {
  return {
    discardResponseBodies: true,
    scenarios: {
      ramp: {
        executor: 'ramping-arrival-rate',
        startRate: number('START_RPS', 50),
        timeUnit: '1s',
        preAllocatedVUs: number('PRE_ALLOCATED_VUS', 100),
        maxVUs: number('MAX_VUS', 1000),
        stages: stages(),
      },
    },
    /*
     * Recorded, not enforced. An aborting threshold would end a run at the
     * first sign of degradation, and the degradation curve is the measurement
     * — Phase 4 exists to name the limiting factor rather than to avoid it.
     */
    thresholds: {
      http_req_duration: [{ threshold: 'p(95)<1000', abortOnFail: false }],
      http_req_failed: [{ threshold: 'rate<0.01', abortOnFail: false }],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  };
}

/**
 * A payload of a stated size rather than a realistic one. Every phase sends
 * the same bytes, so payload size is a recorded constant of the run instead of
 * a variable nobody wrote down.
 */
export function payload(bytes) {
  return { data: 'x'.repeat(Math.max(0, bytes - 12)) };
}
