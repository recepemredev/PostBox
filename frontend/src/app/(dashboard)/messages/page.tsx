import Link from "next/link";
import { listEventTypes, listMessages } from "@/lib/api/server";
import { getIdentity, hasPermission } from "@/lib/identity";
import { EmptyState } from "@/components/EmptyState";
import { StatusBadge } from "@/components/StatusBadge";
import { messageDeliveryTone } from "@/lib/status";
import { canReplayRange, parseMessageFilters, toQueryString } from "@/lib/messages/filters";
import { MessageFiltersForm } from "./MessageFiltersForm";
import { RangeReplayButton } from "./RangeReplayButton";

/**
 * The message list (Step 14): filtered, cursor-paginated, newest last —
 * mirrors GET /v1/messages exactly, since MessageFilters (backend) and
 * this page's own searchParams parsing (lib/messages/filters.ts) describe
 * the identical shape.
 */
export default async function MessagesPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const filters = parseMessageFilters(await searchParams);

  const [{ data: messages, meta }, { data: eventTypes }, identity] = await Promise.all([
    listMessages(toQueryString(filters)),
    listEventTypes(),
    getIdentity(),
  ]);

  const canReplay = hasPermission(identity.permissions, "delivery.replay");

  return (
    <div className="space-y-6">
      <h1 className="text-lg font-semibold text-text">Messages</h1>

      <MessageFiltersForm filters={filters} eventTypes={eventTypes.map((eventType) => eventType.name)} />

      {canReplay ? (
        <RangeReplayButton
          endpoint={filters.endpoint}
          from={filters.from}
          to={filters.to}
          canReplay={canReplayRange(filters)}
        />
      ) : null}

      {messages.length === 0 ? (
        <EmptyState
          title="No messages match these filters"
          hint="Broaden the filters above, or publish an event to see it here."
        />
      ) : (
        <>
          <div className="overflow-hidden rounded-container border border-border">
            <table className="w-full text-sm">
              <thead className="bg-surface text-left text-text-muted">
                <tr>
                  <th className="px-4 py-2 font-medium">Event</th>
                  <th className="px-4 py-2 font-medium">Application</th>
                  <th className="px-4 py-2 font-medium">Deliveries</th>
                  <th className="px-4 py-2 font-medium">Source</th>
                  <th className="px-4 py-2 font-medium">Published</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {messages.map((message) => {
                  const worst = messageDeliveryTone(message.deliveries);

                  return (
                    <tr key={message.id} className="hover:bg-surface">
                      <td className="px-4 py-2">
                        <Link href={`/messages/${message.id}`} className="font-medium text-accent">
                          {message.event_type}
                        </Link>
                      </td>
                      <td className="px-4 py-2 text-text-muted">{message.application_name}</td>
                      <td className="px-4 py-2">
                        <div className="flex items-center gap-2">
                          <StatusBadge tone={worst.tone} label={worst.label} />
                          <span className="font-tabular text-text-muted">
                            {message.deliveries.succeeded}/{message.deliveries.total}
                          </span>
                        </div>
                      </td>
                      <td className="px-4 py-2">
                        {message.source === "dashboard_test" ? (
                          <StatusBadge tone="info" label="Test event" />
                        ) : (
                          <span className="text-text-muted">Producer</span>
                        )}
                      </td>
                      <td className="px-4 py-2 font-tabular text-text-muted">
                        {new Date(message.created_at).toLocaleString()}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {meta.next_cursor !== null ? (
            <div className="flex justify-end">
              <Link
                href={`/messages${toQueryString({ ...filters, cursor: meta.next_cursor })}`}
                className="rounded-control border border-border px-3 py-1.5 text-sm text-text"
              >
                Next page →
              </Link>
            </div>
          ) : null}
        </>
      )}
    </div>
  );
}
