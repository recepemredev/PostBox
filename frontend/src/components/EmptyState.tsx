/**
 * design.md: "the empty state explains what to do next rather than saying
 * 'No data'." `action` is optional because not every empty state has one
 * (an application with no endpoints yet still needs the create form, which
 * lives on the page itself, not inside this component).
 */
export function EmptyState({
  title,
  hint,
  action,
}: {
  title: string;
  hint: string;
  action?: React.ReactNode;
}) {
  return (
    <div className="rounded-container border border-border bg-surface px-6 py-8 text-center">
      <p className="text-sm font-medium text-text">{title}</p>
      <p className="mt-1 text-sm text-text-muted">{hint}</p>
      {action ? <div className="mt-4">{action}</div> : null}
    </div>
  );
}
