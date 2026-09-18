import Link from "next/link";
import { listApplications } from "@/lib/api/server";
import { EmptyState } from "@/components/EmptyState";
import { CreateApplicationForm } from "./CreateApplicationForm";

export default async function ApplicationsPage() {
  const { data: applications } = await listApplications();

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-semibold text-text">Applications</h1>
        <CreateApplicationForm />
      </div>

      {applications.length === 0 ? (
        <EmptyState
          title="No applications yet"
          hint="Create one above — a producer publishes events into an application, and endpoints subscribe beneath it."
        />
      ) : (
        <div className="overflow-hidden rounded-container border border-border">
          <table className="w-full text-sm">
            <thead className="bg-surface text-left text-text-muted">
              <tr>
                <th className="px-4 py-2 font-medium">Name</th>
                <th className="px-4 py-2 font-medium">Endpoints</th>
                <th className="px-4 py-2 font-medium">Created</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {applications.map((application) => (
                <tr key={application.id} className="hover:bg-surface">
                  <td className="px-4 py-2">
                    <Link href={`/applications/${application.id}`} className="font-medium text-accent">
                      {application.name}
                    </Link>
                  </td>
                  <td className="px-4 py-2 font-tabular text-text-muted">{application.endpoint_count}</td>
                  <td className="px-4 py-2 font-tabular text-text-muted">
                    {new Date(application.created_at).toLocaleString()}
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
