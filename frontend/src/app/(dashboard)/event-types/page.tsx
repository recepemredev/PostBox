import { listEventTypes } from "@/lib/api/server";
import { EmptyState } from "@/components/EmptyState";
import { CreateEventTypeForm } from "./CreateEventTypeForm";

export default async function EventTypesPage() {
  const { data: eventTypes } = await listEventTypes();

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-semibold text-text">Event types</h1>
        <CreateEventTypeForm />
      </div>

      {eventTypes.length === 0 ? (
        <EmptyState
          title="No event types registered"
          hint="Register one above before publishing it or subscribing an endpoint to it."
        />
      ) : (
        <div className="overflow-hidden rounded-container border border-border">
          <table className="w-full text-sm">
            <thead className="bg-surface text-left text-text-muted">
              <tr>
                <th className="px-4 py-2 font-medium">Name</th>
                <th className="px-4 py-2 font-medium">Subscribers</th>
                <th className="px-4 py-2 font-medium">Registered</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {eventTypes.map((eventType) => (
                <tr key={eventType.name} className="hover:bg-surface">
                  <td className="px-4 py-2 font-tabular text-text">{eventType.name}</td>
                  <td className="px-4 py-2 font-tabular text-text-muted">{eventType.subscriber_count}</td>
                  <td className="px-4 py-2 font-tabular text-text-muted">
                    {new Date(eventType.created_at).toLocaleString()}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
