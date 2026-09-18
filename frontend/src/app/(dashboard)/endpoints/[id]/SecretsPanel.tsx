import { EmptyState } from "@/components/EmptyState";
import { revokeSecretAction } from "./actions";
import { IssueSecretButton } from "./IssueSecretButton";
import type { components } from "@/types/api";

export function SecretsPanel({
  endpointId,
  secrets,
}: {
  endpointId: string;
  secrets: components["schemas"]["EndpointSecretResource"][];
}) {
  return (
    <div className="space-y-4">
      <IssueSecretButton endpointId={endpointId} />

      {secrets.length === 0 ? (
        <EmptyState
          title="No secrets yet"
          hint="Issue one above to start signing deliveries to this endpoint."
        />
      ) : (
        <ul className="divide-y divide-border rounded-container border border-border">
          {secrets.map((secret) => {
            const revoked = secret.revoked_at !== null;
            const revokeAction = revokeSecretAction.bind(null, endpointId, secret.id);

            return (
              <li key={secret.id} className="flex items-center justify-between px-4 py-2 text-sm">
                <div className="font-tabular text-text">
                  …{secret.last_four}
                  <span className="ml-2 text-text-subtle">
                    {revoked ? `revoked ${new Date(secret.revoked_at).toLocaleString()}` : "active"}
                  </span>
                </div>
                {revoked ? null : (
                  <form action={revokeAction}>
                    <button type="submit" className="text-sm text-danger-fg hover:underline">
                      Revoke
                    </button>
                  </form>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
