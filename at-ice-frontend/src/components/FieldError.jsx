// The short red message under a field (lib/textRules.js nameError).
export default function FieldError({ id, children }) {
  if (!children) return null;
  return <p id={id} role="alert" className="mt-1 text-[11.5px] leading-snug text-red-600 break-words">{children}</p>;
}

// Under a limited name field: the message (if any) on the left and a live
// "38 / 50" counter on the right, red once the limit is reached. The input
// itself stops at the limit (maxLength).
export function NameHelp({ id, value, max, error }) {
  const used = String(value ?? "").length;
  const full = used >= max;
  return (
    <div className="mt-1 flex items-start gap-2 min-w-0">
      <div className="flex-1 min-w-0">
        {error && <p id={id} role="alert" className="text-[11.5px] leading-snug text-red-600 break-words">{error}</p>}
      </div>
      <span aria-live="polite" className={`flex-none text-[11px] tabular-nums ${full ? "text-red-600 font-semibold" : "text-slate-400"}`}
        title={full ? "Limit reached" : undefined}>
        {used} / {max}
      </span>
    </div>
  );
}
