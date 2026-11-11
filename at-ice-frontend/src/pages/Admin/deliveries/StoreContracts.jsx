import { useCallback, useEffect, useRef, useState } from "react";
import Pagination from "../../../components/Pagination";
import { PAGE_SIZES, usePagination } from "../../../lib/usePagination";
import { toast } from "../../../lib/toast";
import { useMaxQuantity } from "../../../lib/limits";
import { QTY_INPUT_PROPS, cleanQty } from "../../../lib/quantity";
import TimeSelect from "../../../components/TimeSelect";
import { api, todayStr, shortDate, timeOf, DAYS, daysLabel, PRODUCT_TYPES, productLabel, storeName, ymd, inputCls, labelCls, btnPrimary, btnGhost } from "./utils";
import { Modal, PlusIcon } from "./shared";
import Req from "../../../components/Req";

const CONTRACT_BADGE = {
  pending: "bg-amber-50 text-amber-700 border-amber-200",
  approved: "bg-emerald-50 text-emerald-700 border-emerald-200",
  ended: "bg-slate-100 text-slate-500 border-slate-200",
  cancelled: "bg-slate-100 text-slate-500 border-slate-200",
};

// An approved contract past its end date shows as ended
const shownStatus = (c) => (c.status === "approved" && c.end_date < todayStr() ? "ended" : c.status);

// Every contract a store has had, opened from its name (CRM > Customers),
// newest first. A store has one contract at a time, approved when it is saved
// (owner, 2026-11-11), so from here Admin edits or cancels that one, or makes
// a new one once nothing is running. Ended and cancelled ones stay as history.
// An older contract still pending can be approved here.
export function StoreContracts({ store, contract: running = null, onClose, onChanged = () => {} }) {
  const [contracts, setContracts] = useState(null);
  const [making, setMaking] = useState(false);
  const [confirm, setConfirm] = useState(null); // { contract, action }
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const list = await api("delivery-contracts");
      setContracts(list
        .filter((c) => (c.stores || []).some((s) => s.id === store.id))
        .sort((a, b) => (b.start_date || "").localeCompare(a.start_date || "") || b.id - a.id));
    } catch (err) {
      toast.error(err.message);
      setContracts([]);
    }
  }, [store.id]);
  useEffect(() => { load(); }, [load]);

  async function act() {
    const { contract, action } = confirm;
    setBusy(true);
    try {
      const res = await api(`delivery-contracts/${contract.id}/${action}`, { method: "POST" });
      toast.success(res.message);
      setConfirm(null);
      load();
      onChanged();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setBusy(false);
    }
  }

  const pager = usePagination(contracts || []);

  if (making) {
    return <ContractForm store={store} contract={running} onClose={() => setMaking(false)} onSaved={() => { setMaking(false); load(); onChanged(); }} />;
  }

  return (
    <>
      <Modal title={`Contracts for ${storeName(store)}`} onClose={onClose} wide>
        <div className="p-5 flex flex-col gap-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-[12px] text-slate-500 max-w-xl">
              Every contract this store has had, newest first, including ended and cancelled ones. Saving a contract makes its daily deliveries at once, each with the store's driver (Deliveries &gt; Driver Stores). A store has one contract at a time.
            </p>
            <button type="button" onClick={() => setMaking(true)} className={`${btnPrimary} flex items-center gap-1.5`}>
              {running ? "Edit contract" : <><PlusIcon /> New contract</>}
            </button>
          </div>
          <div className="overflow-x-auto rounded-xl border border-black/[0.09]">
            <table className="w-full text-[13px]">
              <thead>
                <tr className="text-left text-slate-400 text-[11px] uppercase tracking-widest">
                  <th className="px-3 py-2.5 font-medium">Contract</th>
                  <th className="px-3 py-2.5 font-medium">Dates</th>
                  <th className="px-3 py-2.5 font-medium">Days</th>
                  <th className="px-3 py-2.5 font-medium">Items</th>
                  <th className="px-3 py-2.5 font-medium">Deliveries</th>
                  <th className="px-3 py-2.5 font-medium">Status</th>
                  <th className="px-3 py-2.5 font-medium text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {contracts === null && <tr><td colSpan={7} className="px-3 py-8 text-center text-slate-400">Loading…</td></tr>}
                {contracts?.length === 0 && <tr><td colSpan={7} className="px-3 py-8 text-center text-slate-400">No contracts for this store yet. Make one with New contract.</td></tr>}
                {pager.pageItems.map((c) => (
                  <tr key={c.id} className="border-t border-black/[0.06] align-top">
                    <td className="px-3 py-2.5">
                      <div className="text-slate-800 font-medium font-mono text-[12px]">{c.contract_no}</div>
                      {(c.stores || []).length > 1 && <span className="cell-note text-[11px] text-slate-400">{c.stores.length} stores{c.chain ? ` · ${c.chain}` : ""}</span>}
                    </td>
                    <td className="px-3 py-2.5 text-slate-600 whitespace-nowrap">{shortDate(c.start_date)} – {shortDate(c.end_date)}</td>
                    <td className="px-3 py-2.5 text-slate-600">{daysLabel(c.delivery_days)}<span className="cell-note text-[11px] text-slate-400">{c.delivery_from ? `${timeOf(c.delivery_from)}–${timeOf(c.delivery_time)}` : `by ${timeOf(c.delivery_time)}`}</span></td>
                    <td className="px-3 py-2.5 text-slate-600">{(c.items || []).map((i) => `${i.quantity} × ${productLabel(i.product_type)}`).join(", ")}</td>
                    <td className="px-3 py-2.5 text-slate-600 tabular-nums">
                      {c.status === "pending" ? <span className="text-slate-400">{c.planned} to make</span> : `${c.delivered_count} / ${c.deliveries_count} delivered`}
                    </td>
                    <td className="px-3 py-2.5">
                      <span className={`px-2 py-0.5 rounded-full border text-[11px] font-semibold capitalize ${CONTRACT_BADGE[shownStatus(c)] || ""}`}>{shownStatus(c)}</span>
                      {c.approved_by && <span className="cell-note text-[11px] text-slate-400">by {c.approved_by.name}</span>}
                    </td>
                    <td className="px-3 py-2.5">
                      <div className="flex gap-1.5 justify-end">
                        {c.status === "pending" && (
                          <button data-no-confirm type="button" onClick={() => setConfirm({ contract: c, action: "approve" })} className="px-2 py-1 rounded-md bg-cyan-500 hover:bg-cyan-600 text-white text-[11.5px] font-medium">Approve</button>
                        )}
                        {["pending", "approved"].includes(shownStatus(c)) && (
                          <button data-no-confirm type="button" onClick={() => setConfirm({ contract: c, action: "cancel" })} className="px-2 py-1 rounded-md border border-black/[0.09] text-red-600 text-[11.5px] hover:bg-red-50">Cancel</button>
                        )}
                        {["ended", "cancelled"].includes(shownStatus(c)) && <span className="text-[12px] text-slate-400">History only</span>}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {pager.total > PAGE_SIZES[0] && <Pagination pager={pager} />}
          </div>
          <div className="flex justify-end pt-3 border-t border-black/[0.09]">
            <button type="button" onClick={onClose} className={btnGhost}>Close</button>
          </div>
        </div>
      </Modal>

      {confirm && (
        <Modal title={confirm.action === "approve" ? "Approve contract?" : "Cancel contract?"} onClose={() => setConfirm(null)}>
          <div className="p-5 flex flex-col gap-4">
            <p className="text-[12.5px] text-slate-600">
              {confirm.action === "approve"
                ? <>About <b>{confirm.contract.planned}</b> deliveries under <b>{confirm.contract.contract_no}</b> will be made now, from {shortDate(confirm.contract.start_date)} to {shortDate(confirm.contract.end_date)}, each with its store's assigned driver.</>
                : <>All upcoming deliveries under <b>{confirm.contract.contract_no}</b> that have not started will be cancelled{(confirm.contract.stores || []).length > 1 ? `, for all ${confirm.contract.stores.length} stores` : ""}. Deliveries in transit or delivered stay.</>}
            </p>
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setConfirm(null)} className={btnGhost}>{confirm.action === "approve" ? "Cancel" : "Keep contract"}</button>
              <button type="button" disabled={busy} onClick={act} className={confirm.action === "approve" ? btnPrimary : "px-3.5 py-1.5 rounded-md bg-red-500 hover:bg-red-600 text-white text-[12px] font-medium disabled:opacity-50"}>
                {busy ? "Working…" : confirm.action === "approve" ? "Approve" : "Cancel contract"}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </>
  );
}

// `store` set: opened from one customer's row (Customers > Contract). The
// contract is for that branch only: no chain or store picker, and the branch
// name comes from the customer record. `contract` set: that store's one contract
// is edited (a store has one contract; the upcoming deliveries are made again).
// The Contract ID (DC-{customer code}-{YYMM}-{no.}) is given by the server.
export function ContractForm({ store = null, contract = null, onClose, onSaved }) {
  const maxQty = useMaxQuantity(); // each scheduled delivery is capped like any delivery (config/atice.php)
  const editing = !!contract;
  const [form, setForm] = useState(() => (contract ? {
    name: contract.name, chain: contract.chain || "", store_ids: (contract.stores || []).map((s) => s.id),
    start_date: String(contract.start_date).slice(0, 10), end_date: String(contract.end_date).slice(0, 10),
    delivery_days: contract.delivery_days || [], delivery_time: String(contract.delivery_time || "08:00").slice(0, 5),
    delivery_from: contract.delivery_from ? String(contract.delivery_from).slice(0, 5) : "",
    items: (contract.items || []).map((i) => ({ product_type: i.product_type, quantity: String(i.quantity) })), notes: contract.notes || "",
  } : {
    name: store ? store.name : "", chain: "", store_ids: store ? [store.id] : [], start_date: todayStr(), end_date: "",
    delivery_days: ["mon", "tue", "wed", "thu", "fri", "sat", "sun"], delivery_from: "06:00", delivery_time: "08:00",
    items: [{ product_type: "", quantity: "" }], notes: "",
  }));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const errorRef = useRef(null); // messages the fields cannot show (days, server) are scrolled to
  const set = (patch) => { setForm((f) => ({ ...f, ...patch })); setError(""); };
  const yearAhead = (() => { const d = new Date(); d.setFullYear(d.getFullYear() + 1); return ymd(d); })();

  function toggleDay(day) {
    set({ delivery_days: form.delivery_days.includes(day) ? form.delivery_days.filter((d) => d !== day) : [...form.delivery_days, day] });
  }
  const preset = (n) => set({ delivery_days: DAYS.slice(0, n).map((d) => d.value) });

  // Shows a message no single field can (no delivery day, a server reply) and scrolls to it.
  const fail = (msg) => { setError(msg); requestAnimationFrame(() => errorRef.current?.scrollIntoView({ block: "center", behavior: "smooth" })); };

  async function save(e) {
    e.preventDefault();
    if (!form.end_date || form.end_date < form.start_date) return fail("The end date must be on or after the start date.");
    if (!form.delivery_days.length) return fail("Pick at least one delivery day.");
    if (!form.delivery_from) return fail("Pick the time the delivery window opens.");
    if (form.delivery_from >= form.delivery_time) return fail("The window must open before the Deliver by time.");
    if (form.items.some((i) => !i.product_type || !(Number(i.quantity) >= 1))) return fail("Pick a product and a quantity of at least 1 for every item.");
    setSaving(true);
    try {
      const saved = await api(editing ? `delivery-contracts/${contract.id}` : "delivery-contracts", {
        method: editing ? "PUT" : "POST",
        body: { ...form, chain: form.chain || null, notes: form.notes || null, items: form.items.map((i) => ({ ...i, quantity: Number(i.quantity) })) },
      });
      toast.success(`Contract ${saved.contract_no} was saved. ${saved.message}`);
      onSaved();
    } catch (err) {
      fail(err.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal title={editing ? `Edit contract ${contract.contract_no}` : `Contract for ${storeName(store)}`} onClose={onClose} wide>
      <form onSubmit={save} className="p-5 flex flex-col gap-3.5" noValidate>
        {error && <div ref={errorRef} role="alert" className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[12px] text-red-700">{error}</div>}
        {editing && (
          <div role="status" className="px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-[12px] text-amber-800">
            {storeName(store || contract.stores?.[0])} has one contract. Saving changes remakes its upcoming deliveries that have not started; deliveries already on the road or delivered stay.
          </div>
        )}
        <div>
          <label htmlFor="contract-branch" className={labelCls}>Branch name</label>
          <input id="contract-branch" name="branch_name" value={storeName(store)} readOnly aria-readonly="true" className={`${inputCls} cursor-default`} />
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label htmlFor="contract-from" className={labelCls}>Start date<Req /></label>
            <input id="contract-from" name="start_date" type="date" required min={editing ? undefined : todayStr()} max={yearAhead} value={form.start_date} onChange={(e) => set({ start_date: e.target.value })} className={inputCls} />
          </div>
          <div>
            <label htmlFor="contract-to" className={labelCls}>End date<Req /></label>
            <input id="contract-to" name="end_date" type="date" required min={form.start_date} max={yearAhead} value={form.end_date} onChange={(e) => set({ end_date: e.target.value })} className={inputCls} />
          </div>
        </div>
        {/* The time window: the ice may arrive from the first time, and must by the second (a deadline) */}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label htmlFor="contract-deliver-from" className={labelCls}>Deliver from<Req /></label>
            <TimeSelect id="contract-deliver-from" required value={form.delivery_from} onChange={(e) => set({ delivery_from: e.target.value })} className="w-full" selectClassName={`${inputCls} flex-1 min-w-0`} />
          </div>
          <div>
            <label htmlFor="contract-deliver-by" className={labelCls}>Deliver by (latest)<Req /></label>
            <TimeSelect id="contract-deliver-by" required value={form.delivery_time} onChange={(e) => set({ delivery_time: e.target.value })} className="w-full" selectClassName={`${inputCls} flex-1 min-w-0`} />
          </div>
          <p className="sm:col-span-2 -mt-1.5 text-[11px] text-slate-400">The store gets its ice inside this window, e.g. 6:00–8:00 AM for a mall that opens at 9:00.</p>
        </div>
        <div>
          <div className={labelCls}>Delivery days<Req /></div>
          <div className="flex flex-wrap items-center gap-1.5">
            {/* Lets the form check "at least one day" before anything is sent, like any required field */}
            <input
              type="text" name="delivery_days_check" tabIndex={-1} aria-hidden="true" className="sr-only" value={form.delivery_days.join(",")} onChange={() => {}}
              ref={(el) => el?.setCustomValidity(form.delivery_days.length ? "" : "Pick at least one delivery day.")}
            />
            {DAYS.map((d) => (
              <button key={d.value} type="button" aria-pressed={form.delivery_days.includes(d.value)} onClick={() => toggleDay(d.value)}
                className={`px-2.5 py-1 rounded-md border text-[12px] font-medium ${form.delivery_days.includes(d.value) ? "bg-cyan-500 border-cyan-500 text-white" : "bg-white border-black/[0.09] text-slate-600"}`}>
                {d.label}
              </button>
            ))}
            <span className="mx-1 text-slate-300">|</span>
            <button type="button" onClick={() => preset(7)} className="text-[11.5px] text-cyan-700 font-medium">Mon–Sun</button>
            <button type="button" onClick={() => preset(6)} className="text-[11.5px] text-cyan-700 font-medium">Mon–Sat</button>
            <button type="button" onClick={() => preset(5)} className="text-[11.5px] text-cyan-700 font-medium">Mon–Fri</button>
          </div>
        </div>
        <div>
          <div className={labelCls}>Items per store per delivery<Req /></div>
          <div className="flex flex-col gap-2">
            {form.items.map((it, i) => (
              <div key={i} className="grid grid-cols-[minmax(0,1fr)_72px_auto] sm:grid-cols-[1fr_110px_auto] gap-2">
                <select name="product_type" required value={it.product_type} aria-label={`Product ${i + 1}`} onChange={(e) => set({ items: form.items.map((x, j) => (j === i ? { ...x, product_type: e.target.value } : x)) })} className={inputCls}>
                  <option value="">Select product…</option>
                  {PRODUCT_TYPES.map((p) => <option key={p.value} value={p.value} disabled={form.items.some((o, j) => j !== i && o.product_type === p.value)}>{p.label}</option>)}
                </select>
                <input {...QTY_INPUT_PROPS} name={`quantity-${i + 1}`} required placeholder="Qty" aria-label={`Quantity ${i + 1}, at most ${maxQty}`} value={it.quantity} onChange={(e) => set({ items: form.items.map((x, j) => (j === i ? { ...x, quantity: cleanQty(e.target.value, maxQty) } : x)) })} className={inputCls} />
                <button type="button" disabled={form.items.length === 1} onClick={() => set({ items: form.items.filter((_, j) => j !== i) })} className="px-2 rounded-md border border-black/[0.09] text-red-500 text-[12px] disabled:opacity-30">Remove</button>
              </div>
            ))}
          </div>
          {form.items.length < PRODUCT_TYPES.length && (
            <button type="button" onClick={() => set({ items: [...form.items, { product_type: "", quantity: "" }] })} className="mt-2 text-[12px] font-medium text-cyan-700">+ Add item</button>
          )}
          <p className="text-[11px] text-slate-400 mt-1.5">Prices come from Customer Prices for each store. Admin can change one day's quantities later.</p>
        </div>
        <div>
          <label htmlFor="contract-notes" className={labelCls}>Notes</label>
          <textarea id="contract-notes" name="notes" rows={3} maxLength={1000} value={form.notes} onChange={(e) => set({ notes: e.target.value })} className={inputCls} />
        </div>
        <div className="flex justify-end gap-2 pt-3 border-t border-black/[0.09]">
          <button type="button" onClick={onClose} className={btnGhost}>Close</button>
          <button type="submit" disabled={saving} className={btnPrimary}>{saving ? "Saving…" : editing ? "Save changes" : "Save contract"}</button>
        </div>
      </form>
    </Modal>
  );
}
