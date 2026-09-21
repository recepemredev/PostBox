import { StatusBadge } from "@/components/StatusBadge";
import { AttemptTimeline } from "@/components/AttemptTimeline";
import { PayloadInspector } from "@/components/PayloadInspector";
import { deliveryStatusTone } from "@/lib/status";
import { ReplayButton } from "./ReplayButton";
import type { components } from "@/types/api";

type Delivery = components["schemas"]["DeliveryResource"];
type Attempt = components["schemas"]["DeliveryAttemptResource"];

/**
 * One delivery's own card: its status, its retry timeline, and every
 * attempt's request/response — replay_id (D78) is what distinguishes an
 * original delivery from one Recovery opened, since a message can carry
 * more than one delivery to the same endpoint.
 */
export function DeliveryCard({
  messageId,
  delivery,
  attempts,
  canReplay,
}: {
  messageId: string;
  delivery: Delivery;
  attempts: Attempt[];
  canReplay: boolean;
}) {
  const status = deliveryStatusTone(delivery.status, delivery.attempt_count);
  const nextAttemptAt = delivery.status === "pending" ? delivery.next_attempt_at || null : null;

  return (
    <div className="space-y-3 rounded-container border border-border p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="font-medium text-text">{delivery.endpoint_name}</span>
          <StatusBadge tone={status.tone} label={status.label} />
          {delivery.replay_id !== null ? <StatusBadge tone="neutral" label="Replay" /> : null}
        </div>

        {canReplay ? (
          <ReplayButton messageId={messageId} endpointId={delivery.endpoint_id} target={delivery.endpoint_name} />
        ) : null}
      </div>

      {delivery.failure_reason !== null ? <p className="text-sm text-danger-fg">{delivery.failure_reason}</p> : null}

      <AttemptTimeline attempts={attempts} nextAttemptAt={nextAttemptAt} />

      {attempts.length > 0 ? (
        <div className="space-y-2">
          {attempts.map((attempt) => (
            <details key={attempt.id} className="rounded-control border border-border">
              <summary className="cursor-pointer px-3 py-2 text-sm text-text-muted">
                Attempt #{attempt.attempt_number} — {attempt.outcome}
              </summary>
              <div className="border-t border-border p-3">
                <PayloadInspector attempt={attempt} />
              </div>
            </details>
          ))}
        </div>
      ) : null}
    </div>
  );
}
