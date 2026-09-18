import Link from "next/link";
import { notFound } from "next/navigation";
import { ApiError } from "@/lib/api/errors";
import { getEndpoint, listEventTypes, listSecrets } from "@/lib/api/server";
import { StatusBadge } from "@/components/StatusBadge";
import { breakerStateTone } from "@/lib/status";
import { EndpointEditForm } from "./EndpointEditForm";
import { SubscriptionsForm } from "./SubscriptionsForm";
import { TestEventForm } from "./TestEventForm";
import { SecretsPanel } from "./SecretsPanel";

export default async function EndpointDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  let endpoint;
  let eventTypes;
  let secrets;

  try {
    [endpoint, { data: eventTypes }, { data: secrets }] = await Promise.all([
      getEndpoint(id),
      listEventTypes(),
      listSecrets(id),
    ]);
  } catch (error) {
    if (error instanceof ApiError && error.isNotFound) {
      notFound();
    }
    throw error;
  }

  const breaker = endpoint.breaker !== null ? breakerStateTone(endpoint.breaker.state) : null;

  return (
    <div className="space-y-8">
      <div>
        <Link href={`/applications/${endpoint.application_id}`} className="text-sm text-text-muted hover:text-text">
          ← Application
        </Link>
        <div className="mt-2 flex items-center gap-3">
          <h1 className="text-lg font-semibold text-text">{endpoint.name}</h1>
          {breaker ? <StatusBadge tone={breaker.tone} label={`Breaker: ${breaker.label}`} /> : null}
        </div>
      </div>

      <section className="space-y-2">
        <h2 className="text-sm font-medium text-text-muted">Configuration</h2>
        <EndpointEditForm endpointId={endpoint.id} name={endpoint.name} url={endpoint.url} status={endpoint.status} />
      </section>

      <section className="space-y-2">
        <h2 className="text-sm font-medium text-text-muted">Subscriptions</h2>
        <SubscriptionsForm
          endpointId={endpoint.id}
          eventTypeNames={eventTypes.map((eventType) => eventType.name)}
          subscribed={endpoint.subscriptions}
        />
      </section>

      <section className="space-y-2">
        <h2 className="text-sm font-medium text-text-muted">Send a test event</h2>
        <TestEventForm endpointId={endpoint.id} subscribedEventTypes={endpoint.subscriptions} />
      </section>

      <section className="space-y-2">
        <h2 className="text-sm font-medium text-text-muted">Signing secrets</h2>
        <SecretsPanel endpointId={endpoint.id} secrets={secrets} />
      </section>
    </div>
  );
}
