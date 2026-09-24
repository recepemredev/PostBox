import type { components } from "@/types/api";

type Queue = components["schemas"]["QueueWorkloadResource"];

/**
 * The three delivery queues' current workload (Step 17), read straight from
 * Horizon's own repositories. Shared infrastructure, not this tenant's own
 * data — every tenant's deliveries drain the same three queues — which is
 * why this table carries no tenant column and the caption says so plainly
 * rather than leaving a viewer to assume these numbers are theirs alone.
 */
export function QueueTable({ queues }: { queues: Queue[] }) {
  return (
    <div className="space-y-2">
      <p className="text-sm text-text-muted">Shared across every tenant.</p>

      <div className="overflow-hidden rounded-container border border-border">
        <table className="w-full text-sm">
          <thead className="bg-surface text-left text-text-muted">
            <tr>
              <th className="px-4 py-2 font-medium">Queue</th>
              <th className="px-4 py-2 font-medium">Length</th>
              <th className="px-4 py-2 font-medium">Wait</th>
              <th className="px-4 py-2 font-medium">Processes</th>
              <th className="px-4 py-2 font-medium">Avg runtime</th>
              <th className="px-4 py-2 font-medium">Throughput</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {queues.map((queue) => (
              <tr key={queue.name}>
                <td className="px-4 py-2 font-medium text-text">{queue.name}</td>
                <td className="px-4 py-2 font-tabular text-text-muted">{queue.length}</td>
                <td className="px-4 py-2 font-tabular text-text-muted">{queue.wait_ms}ms</td>
                <td className="px-4 py-2 font-tabular text-text-muted">{queue.processes}</td>
                <td className="px-4 py-2 font-tabular text-text-muted">{queue.runtime_ms}ms</td>
                <td className="px-4 py-2 font-tabular text-text-muted">{queue.throughput}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
