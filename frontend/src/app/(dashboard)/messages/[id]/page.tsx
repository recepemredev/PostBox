import Link from "next/link";
import { notFound } from "next/navigation";
import { ApiError } from "@/lib/api/errors";
import { getMessage, listAttempts } from "@/lib/api/server";
import { getIdentity, hasPermission } from "@/lib/identity";
import { EmptyState } from "@/components/EmptyState";
import { DeliveryCard } from "./DeliveryCard";
import { ReplayButton } from "./ReplayButton";

/**
 * The message detail screen (Step 14): the payload as stored, and every
 * delivery the message ever opened — original and replayed alike (D78).
 * A delivery's own attempt list is fetched alongside it: at most one page
 * each (the default Ledger page size comfortably covers RetryPolicy's own
 * max_attempts), so no attempt-level pagination UI exists here.
 */
export default async function MessageDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  let message;
  let identity;

  try {
    [message, identity] = await Promise.all([getMessage(id), getIdentity()]);
  } catch (error) {
    if (error instanceof ApiError && error.isNotFound) {
      notFound();
    }
    throw error;
  }

  const attemptsByDelivery = await Promise.all(
    message.deliveries.map((delivery) => listAttempts(delivery.id)),
  );

  const canReplay = hasPermission(identity.permissions, "delivery.replay");

  return (
    <div className="space-y-8">
      <div>
        <Link href="/messages" className="text-sm text-text-muted hover:text-text">
          ← Messages
        </Link>
        <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-lg font-semibold text-text">{message.event_type}</h1>
            <p className="text-sm text-text-muted">
              {message.application_name} · {new Date(message.created_at).toLocaleString()}
              {message.source === "dashboard_test" ? " · Test event" : ""}
            </p>
          </div>
          {canReplay ? <ReplayButton messageId={message.id} endpointId={null} target="every subscriber" /> : null}
        </div>
      </div>

      <section className="space-y-2">
        <h2 className="text-sm font-medium text-text-muted">Payload</h2>
        <pre className="max-h-96 overflow-auto rounded-container border border-border bg-surface p-4 font-tabular text-xs text-text">
          {JSON.stringify(message.payload, null, 2)}
        </pre>
      </section>

      <section className="space-y-2">
        <h2 className="text-sm font-medium text-text-muted">Deliveries</h2>

        {message.deliveries.length === 0 ? (
          <EmptyState
            title="No deliveries"
            hint="No endpoint was subscribed to this event type when it was published."
          />
        ) : (
          <div className="space-y-4">
            {message.deliveries.map((delivery, index) => (
              <DeliveryCard
                key={delivery.id}
                messageId={message.id}
                delivery={delivery}
                attempts={attemptsByDelivery[index]?.data ?? []}
                canReplay={canReplay}
              />
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
