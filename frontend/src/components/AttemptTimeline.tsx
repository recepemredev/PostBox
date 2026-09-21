import { StatusBadge } from "@/components/StatusBadge";
import { formatDuration } from "@/lib/duration";
import { attemptOutcomeTone } from "@/lib/status";
import type { components } from "@/types/api";

type Attempt = components["schemas"]["DeliveryAttemptResource"];

/**
 * design.md: "One horizontal track per message: attempt, outcome, delay to
 * the next attempt. This is the screen the README screenshots." Built per
 * delivery rather than per message — D78 means a message can carry more
 * than one delivery to the same endpoint, and each has its own independent
 * retry schedule, so "one track" only means something once it is scoped to
 * one delivery's own attempts.
 */
export function AttemptTimeline({ attempts, nextAttemptAt }: { attempts: Attempt[]; nextAttemptAt: string | null }) {
  if (attempts.length === 0) {
    return <p className="text-sm text-text-subtle">No attempts yet.</p>;
  }

  return (
    <div className="flex items-center gap-1 overflow-x-auto py-2" aria-label="Attempt timeline">
      {attempts.map((attempt, index) => {
        const tone = attemptOutcomeTone(attempt.outcome);
        const next = attempts[index + 1];
        const delayMs = next ? new Date(next.created_at).getTime() - new Date(attempt.created_at).getTime() : null;
        const isLast = index === attempts.length - 1;

        return (
          <div key={attempt.id} className="flex items-center gap-1">
            <div className="flex flex-col items-center gap-0.5">
              <StatusBadge tone={tone.tone} label={`#${attempt.attempt_number}`} />
              <span className="text-xs text-text-subtle">{tone.label}</span>
            </div>

            {delayMs !== null ? (
              <span className="whitespace-nowrap px-1 font-tabular text-xs text-text-subtle">
                {formatDuration(delayMs)} →
              </span>
            ) : isLast && nextAttemptAt !== null ? (
              <span className="whitespace-nowrap px-1 font-tabular text-xs text-warning-fg">
                next at {new Date(nextAttemptAt).toLocaleTimeString()}
              </span>
            ) : null}
          </div>
        );
      })}
    </div>
  );
}
