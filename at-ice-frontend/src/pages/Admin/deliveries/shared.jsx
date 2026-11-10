import { useState } from "react";
import { STATUS_STYLE, inputCls, storeName } from "./utils";
import { Icon } from "../../../components/icons";
import { CUSTOMER_TYPES } from "../../../lib/customerTypes";
import { timingPill, deliverByTime } from "../../../lib/deliverBy";

// Small pieces of UI shared by Admin > Deliveries (helpers are in utils.js).

// A delivery's Deliver by time ("by 8:00 AM") and, under it, On time, Late or
// Overdue (lib/deliverBy.js). "—" for a delivery with no Deliver by time.
export function DeliverByCell({ delivery }) {
  if (!delivery.due_at) return <span className="text-slate-300">—</span>;
  const pill = timingPill(delivery.timing);
  return (
    <div className="whitespace-nowrap">
      <div className="text-slate-600">by {deliverByTime(delivery.due_at)}</div>
      {pill && <span className={`cell-note px-1.5 py-px rounded-full text-[10px] font-semibold ${pill.cls}`}>{pill.label}</span>}
    </div>
  );
}

export function StatusCell({ status }) {
  const s = STATUS_STYLE[status] || STATUS_STYLE.pending;
  return (
    <span className={`inline-flex items-center justify-center min-w-[84px] px-2.5 py-0.5 rounded-full border text-[11px] font-semibold ${s.bg} ${s.text}`}>
      {s.label}
    </span>
  );
}

export function ReassignedTag({ delivery }) {
  if (!delivery?.reassigned_from) return null;
  return (
    <span className="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10.5px] font-semibold bg-violet-50 text-violet-700 border border-violet-200 whitespace-nowrap">
      Reassigned from {delivery.reassigned_from.name}
    </span>
  );
}

export const XIcon = () => <Icon name="x" size={16} />;
export const PlusIcon = () => <Icon name="plus" size={14} />;
export const TrashIcon = () => <Icon name="trashAlt" size={13} />;

export function Modal({ title, onClose, children, wide = false }) {
  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      {/* Shell: the title bar stays put and only the body scrolls (controls.css .modal-shell) */}
      <div className={`modal-shell bg-white rounded-xl border border-black/[0.09] w-full ${wide ? "max-w-2xl" : "max-w-md"} max-h-[88vh] flex flex-col overflow-hidden`}>
        <div className="shrink-0 px-5 py-4 border-b border-black/[0.09] flex items-center justify-between">
          <h3 className="text-[14px] font-semibold text-slate-800">{title}</h3>
          <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600" aria-label="Close"><XIcon /></button>
        </div>
        <div className="modal-body flex-1 min-h-0 overflow-y-auto overscroll-contain">
          {children}
        </div>
      </div>
    </div>
  );
}

// Checklist of stores with a search box and a customer type filter (Dealers,
// SM Malls, Alfamart: the same types as the page toolbar). The chain is found
// by the search box. `note(store)` adds a small grey line (e.g. its current driver).
const typeOf = (s) => s?.customer_type || "dealer"; // no type = a dealer, as everywhere
export function StorePicker({ stores, value, onChange, note }) {
  const [q, setQ] = useState("");
  const [type, setType] = useState("");
  const needle = q.trim().toLowerCase();
  // Codes match with or without dashes and spaces: "TIN-SJDM-001", "tinsjdm001", "sjdm 001"
  const bare = (t) => t.replace(/[^a-z0-9]/g, "");
  const list = stores.filter((s) => {
    if (type && typeOf(s) !== type) return false;
    if (!needle) return true;
    const text = `${s.name} ${s.branch_code || ""} ${s.customer_code || ""} ${s.city || ""} ${s.chain || ""}`.toLowerCase();
    return text.includes(needle) || (bare(needle) !== "" && bare(text).includes(bare(needle)));
  });
  const picked = new Set(value.map(String));
  const allPicked = list.length > 0 && list.every((s) => picked.has(String(s.id)));

  function toggle(id) {
    const k = String(id);
    onChange(picked.has(k) ? value.filter((v) => String(v) !== k) : [...value, id]);
  }
  function toggleAll() {
    const ids = list.map((s) => s.id);
    onChange(allPicked
      ? value.filter((v) => !ids.some((id) => String(id) === String(v)))
      : [...new Set([...value, ...ids])]);
  }

  return (
    <div className="flex flex-col gap-2">
      {/* Search and filter sit outside the list box, so their edges line up with the fields above */}
      <div className="flex flex-col sm:flex-row gap-2">
        <input name="q" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search store, code, chain, city…" aria-label="Search stores" className={inputCls} />
        <select name="customer_type" value={type} onChange={(e) => setType(e.target.value)} aria-label="Filter by customer type" className={`${inputCls} sm:max-w-[150px]`}>
          <option value="">All types ({stores.length})</option>
          {Object.entries(CUSTOMER_TYPES).map(([v, t]) => <option key={v} value={v}>{t.label} ({stores.filter((s) => typeOf(s) === v).length})</option>)}
        </select>
      </div>
    <div className="rounded-lg border border-black/[0.09]">
      <label className="flex items-center gap-2 px-3 py-1.5 border-b border-black/[0.07] text-[12px] text-slate-600 cursor-pointer">
        <input name="select_all_stores" type="checkbox" checked={allPicked} onChange={toggleAll} disabled={list.length === 0} />
        Select all shown ({list.length}) · {value.length} picked
      </label>
      <ul className="max-h-56 overflow-y-auto py-1">
        {list.length === 0 && <li className="px-3 py-2 text-[12px] text-slate-400">No store matches.</li>}
        {list.map((s) => (
          <li key={s.id}>
            <label className="flex items-start gap-2 px-3 py-1.5 text-[13px] cursor-pointer hover:bg-slate-50">
              <input name="select_store" type="checkbox" className="mt-0.5" checked={picked.has(String(s.id))} onChange={() => toggle(s.id)} />
              <span className="min-w-0">
                <span className="block text-slate-800 truncate">{storeName(s)}</span>
                <span className="block text-[11px] text-slate-400">{[s.chain, s.city, note?.(s)].filter(Boolean).join(" · ")}</span>
              </span>
            </label>
          </li>
        ))}
      </ul>
    </div>
    </div>
  );
}

