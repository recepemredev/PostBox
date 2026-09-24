import { EmptyState } from "@/components/EmptyState";
import { StatusBadge } from "@/components/StatusBadge";
import { breakerStateTone } from "@/lib/status";
import type { components } from "@/types/api";

type Breakers = components["schemas"]["OperationsResource"]["breakers"];

/**
 * This tenant's own circuit breakers (Step 17) — the counts always render,
 * since "0 open, 0 half-open" is itself the answer an operator is asking
 * for; only the tripped list underneath has an empty state (design.md: the
 * empty state explains what to do next rather than saying "No data" — here
 * there is nothing to do next, because nothing is tripped).
 */
export function BreakerList({ breakers }: { breakers: Breakers }) {
  return (
    <div className="space-y-3">
      <div className="flex gap-4">
        <StatusBadge tone="success" label={`${breakers.closed} closed`} />
        <StatusBadge tone="danger" label={`${breakers.open} open`} />
        <StatusBadge tone="warning" label={`${breakers.half_open} half-open`} />
      </div>

      {breakers.tripped.length === 0 ? (
        <EmptyState title="All circuits closed" hint="No endpoint is currently refusing deliveries." />
      ) : (
        <div className="overflow-hidden rounded-container border border-border">
          <table className="w-full text-sm">
            <thead className="bg-surface text-left text-text-muted">
              <tr>
                <th className="px-4 py-2 font-medium">Endpoint</th>
                <th className="px-4 py-2 font-medium">State</th>
                <th className="px-4 py-2 font-medium">Changed</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {breakers.tripped.map((breaker) => {
                const tone = breakerStateTone(breaker.state);

                return (
                  <tr key={breaker.endpoint_id}>
                    <td className="px-4 py-2 font-medium text-text">{breaker.endpoint_name}</td>
                    <td className="px-4 py-2">
                      <StatusBadge tone={tone.tone} label={tone.label} />
                    </td>
                    <td className="px-4 py-2 font-tabular text-text-muted">
                      {new Date(breaker.state_changed_at).toLocaleString()}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
