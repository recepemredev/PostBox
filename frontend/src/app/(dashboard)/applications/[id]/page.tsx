import Link from "next/link";
import { notFound } from "next/navigation";
import { getApplication, listEndpoints } from "@/lib/api/server";
import { ApiError } from "@/lib/api/errors";
import { EmptyState } from "@/components/EmptyState";
import { StatusBadge } from "@/components/StatusBadge";
import { endpointStatusTone } from "@/lib/status";
import { RenameApplicationForm } from "./RenameApplicationForm";
import { CreateEndpointForm } from "./CreateEndpointForm";

export default async function ApplicationDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  let application;
  let endpoints;

  try {
    [application, { data: endpoints }] = await Promise.all([getApplication(id), listEndpoints(id)]);
  } catch (error) {
    if (error instanceof ApiError && error.isNotFound) {
      notFound();
    }
    throw error;
  }

  return (
    <div className="space-y-8">
      <div>
        <Link href="/applications" className="text-sm text-text-muted hover:text-text">
          ← Applications
        </Link>
        <div className="mt-2">
          <RenameApplicationForm applicationId={application.id} name={application.name} />
        </div>
      </div>

      <section className="space-y-4">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-medium text-text-muted">Endpoints</h2>
          <CreateEndpointForm applicationId={application.id} />
        </div>

        {endpoints.length === 0 ? (
          <EmptyState
            title="No endpoints yet"
            hint="Create one above, subscribe it to an event type, and send a test event to prove it works."
          />
        ) : (
          <div className="overflow-hidden rounded-container border border-border">
            <table className="w-full text-sm">
              <thead className="bg-surface text-left text-text-muted">
                <tr>
                  <th className="px-4 py-2 font-medium">Name</th>
                  <th className="px-4 py-2 font-medium">URL</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Breaker</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {endpoints.map((endpoint) => {
                  const status = endpointStatusTone(endpoint.status);

                  return (
                    <tr key={endpoint.id} className="hover:bg-surface">
                      <td className="px-4 py-2">
                        <Link href={`/endpoints/${endpoint.id}`} className="font-medium text-accent">
                          {endpoint.name}
                        </Link>
                      </td>
                      <td className="max-w-xs truncate px-4 py-2 font-tabular text-text-muted">{endpoint.url}</td>
                      <td className="px-4 py-2">
                        <StatusBadge tone={status.tone} label={status.label} />
                      </td>
                      <td className="px-4 py-2 text-text-muted">
                        {endpoint.breaker === null ? "—" : endpoint.breaker.state}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
