import { useCallback, useEffect, useRef, useState } from "react";
import { toast } from "../../../lib/toast";
import { useMaxQuantity } from "../../../lib/limits";
import { QTY_INPUT_PROPS, cleanQty } from "../../../lib/quantity";
import { api, todayStr, shortDate, timeOf, DAYS, daysLabel, PRODUCT_TYPES, productLabel, storeName, ymd, inputCls, labelCls, btnPrimary, btnGhost } from "./utils";
import { Modal, StorePicker, PlusIcon } from "./shared";
import Req from "../../../components/Req";

const CONTRACT_BADGE = {
  pending: "bg-amber-50 text-amber-700 border-amber-200",
  approved: "bg-emerald-50 text-emerald-700 border-emerald-200",
  cancelled: "bg-slate-100 text-slate-500 border-slate-200",
};

// Contracts: one delivery schedule per customer (a whole chain such as SM, or
// one store), a date range and delivery days. Approving it makes every
// daily delivery, so nobody fills in a form each day.
// The page toolbar passes `search`, `type` (customer type) and `status`.
export default function ContractsTab({ stores, live, onChanged, search = "", type = "", status = "all", onLoaded }) {
  const [contracts, setContracts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [confirm, setConfirm] = useState(null); // { contract, action }
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setContracts(await api("delivery-contracts"));
    } catch (err) {
      toast.error(err.message);
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => { load(); }, [load, live]);
  useEffect(() => { onLoaded?.(contracts); }, [contracts, onLoaded]);

  const shown = contracts.filter((c) =>
    (status === "all" || c.status === status)
    && (!type || (c.stores || []).some((s) => (s.customer_type || "dealer") === type))
    && (!search || `${c.name} ${c.contract_no} ${c.chain || ""} ${(c.stores || []).map((s) => s.name).join(" ")}`.toLowerCase().includes(search)));
  const narrowed = !!(search || type || status !== "all");

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

  return (
    <div className="p-[18px] flex flex-col gap-3">
      <div className="flex items-center justify-between gap-3">
        <p className="text-[12px] text-slate-500 max-w-2xl">
          One schedule per customer with a date range and delivery days. Once Admin approves it, the daily deliveries are made automatically and go to each store's assigned driver (Driver Stores tab).
        </p>
        <button type="button" onClick={() => setShowForm(true)} className="flex items-center gap-1.5 px-3 py-1.5 bg-cyan-500 hover:bg-cyan-600 text-white text-[12px] font-medium rounded-lg flex-shrink-0">
          <PlusIcon /> New Contract
        </button>
      </div>

      <div className="overflow-x-auto lg:overflow-visible rounded-xl border border-black/[0.09]">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="text-left text-slate-400 text-[11px] uppercase tracking-widest">
              <th className="px-3 py-2.5 font-medium">Contract</th>
              <th className="px-3 py-2.5 font-medium">Stores</th>
              <th className="px-3 py-2.5 font-medium">Dates</th>
              <th className="px-3 py-2.5 font-medium">Days</th>
              <th className="px-3 py-2.5 font-medium">Items per store</th>
              <th className="px-3 py-2.5 font-medium">Deliveries</th>
              <th className="px-3 py-2.5 font-medium">Status</th>
              <th className="px-3 py-2.5 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading && contracts.length === 0 && <tr><td colSpan={8} className="px-3 py-8 text-center text-slate-400">Loading…</td></tr>}
            {!loading && shown.length === 0 && <tr><td colSpan={8} className="px-3 py-8 text-center text-slate-400">{narrowed && contracts.length ? "No contracts match this filter." : "No contracts yet."}</td></tr>}
            {shown.map((c) => (
              <tr key={c.id} className="border-t border-black/[0.06] align-top">
                <td className="px-3 py-2.5">
                  <div className="text-slate-800 font-medium">{c.name}</div>
                  <div className="text-[11px] text-slate-400 font-mono">{c.contract_no}{c.chain ? ` · ${c.chain}` : ""}</div>
                </td>
                <td className="px-3 py-2.5 text-slate-600" title={(c.stores || []).map((s) => s.name).join("\n")}>
                  {(c.stores || []).length}
                </td>
                <td className="px-3 py-2.5 text-slate-600 whitespace-nowrap">{shortDate(c.start_date)} – {shortDate(c.end_date)}</td>
                <td className="px-3 py-2.5 text-slate-600">{daysLabel(c.delivery_days)}<div className="text-[11px] text-slate-400">by {timeOf(c.delivery_time)}</div></td>
                <td className="px-3 py-2.5 text-slate-600">{(c.items || []).map((i) => `${i.quantity} × ${productLabel(i.product_type)}`).join(", ")}</td>
                <td className="px-3 py-2.5 text-slate-600 tabular-nums">
                  {c.status === "pending" ? <span className="text-slate-400">{c.planned} to make</span> : `${c.delivered_count} / ${c.deliveries_count} delivered`}
                </td>
                <td className="px-3 py-2.5">
                  <span className={`px-2 py-0.5 rounded-full border text-[11px] font-semibold capitalize ${CONTRACT_BADGE[c.status] || ""}`}>{c.status}</span>
                  {c.approved_by && <div className="text-[11px] text-slate-400 mt-1">by {c.approved_by.name}</div>}
                </td>
                <td className="px-3 py-2.5">
                  <div className="flex gap-1.5 justify-end">
                    {c.status === "pending" && (
                      <button data-no-confirm type="button" onClick={() => setConfirm({ contract: c, action: "approve" })} className="px-2 py-1 rounded-md bg-cyan-500 hover:bg-cyan-600 text-white text-[11.5px] font-medium">Approve</button>
                    )}
                    {c.status !== "cancelled" && (
                      <button data-no-confirm type="button" onClick={() => setConfirm({ contract: c, action: "cancel" })} className="px-2 py-1 rounded-md border border-black/[0.09] text-red-600 text-[11.5px] hover:bg-red-50">Cancel</button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {showForm && (
        <ContractForm
          stores={stores}
          onClose={() => setShowForm(false)}
          onSaved={() => { setShowForm(false); load(); }}
        />
      )}

      {confirm && (
        <Modal title={confirm.action === "approve" ? "Approve contract?" : "Cancel contract?"} onClose={() => setConfirm(null)}>
          <div className="p-5 flex flex-col gap-4">
            <p className="text-[12.5px] text-slate-600">
              {confirm.action === "approve"
                ? <>About <b>{confirm.contract.planned}</b> deliveries for <b>{confirm.contract.name}</b> will be made now, from {shortDate(confirm.contract.start_date)} to {shortDate(confirm.contract.end_date)}, each with its store's assigned driver.</>
                : <>All of <b>{confirm.contract.name}</b>'s upcoming deliveries that have not started will be cancelled. Deliveries in transit or delivered stay.</>}
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
    </div>
  );
}

// `store` set: opened from one customer's row (Customers > Contract). The
// contract is for that branch only: no chain or store picker, and the branch
// name comes from the customer record. Also used by the New Contract button.
// The Contract ID (DC-{customer code}-{YYMM}-{no.}) is given by the server.
export function ContractForm({ stores, store = null, onClose, onSaved }) {
  const maxQty = useMaxQuantity(); // each scheduled delivery is capped like any delivery (config/atice.php)
  const [form, setForm] = useState({
    name: store ? store.name : "", chain: "", store_ids: store ? [store.id] : [], start_date: todayStr(), end_date: "",
    delivery_days: ["mon", "tue", "wed", "thu", "fri", "sat", "sun"], delivery_time: "08:00",
    items: [{ product_type: "", quantity: "" }], notes: "",
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const errorRef = useRef(null); // messages the fields cannot show (days, server) are scrolled to
  const [current, setCurrent] = useState([]); // this store's running or pending contracts
  const [nextId, setNextId] = useState(""); // the Contract ID this one gets on save
  const set = (patch) => { setForm((f) => ({ ...f, ...patch })); setError(""); };
  const chains = [...new Set(stores.map((s) => s.chain).filter(Boolean))].sort();
  const yearAhead = (() => { const d = new Date(); d.setFullYear(d.getFullYear() + 1); return ymd(d); })();
  const chainStores = (chain) => stores.filter((s) => s.chain === chain);

  // A store with a contract already running would get two deliveries a day
  // if both are approved, so Admin is told before saving another one.
  useEffect(() => {
    if (!store) return;
    let alive = true;
    api("delivery-contracts")
      .then((list) => {
        if (!alive) return;
        setCurrent(list.filter((c) => c.status !== "cancelled" && c.end_date >= todayStr()
          && (c.stores || []).some((s) => s.id === store.id)));
      })
      .catch(() => {});
    api(`delivery-contracts/next-number?store_ids[]=${store.id}`)
      .then((r) => { if (alive) setNextId(r.contract_no); })
      .catch(() => {});
    return () => { alive = false; };
  }, [store]);

  // Picking a chain fills in the name and every active store of that chain.
  function pickChain(chain) {
    set({
      chain,
      name: form.name || chain,
      store_ids: chain ? chainStores(chain).map((s) => s.id) : form.store_ids,
    });
  }
  function toggleDay(day) {
    set({ delivery_days: form.delivery_days.includes(day) ? form.delivery_days.filter((d) => d !== day) : [...form.delivery_days, day] });
  }
  const preset = (n) => set({ delivery_days: DAYS.slice(0, n).map((d) => d.value) });

  // Shows a message no single field can (no delivery day, a server reply) and scrolls to it.
  const fail = (msg) => { setError(msg); requestAnimationFrame(() => errorRef.current?.scrollIntoView({ block: "center", behavior: "smooth" })); };

  async function save(e) {
    e.preventDefault();
    if (!form.name.trim()) return fail("Enter a name for the contract.");
    if (!form.store_ids.length) return fail("Pick at least one store.");
    if (!form.end_date || form.end_date < form.start_date) return fail("The end date must be on or after the start date.");
    if (!form.delivery_days.length) return fail("Pick at least one delivery day.");
    if (form.items.some((i) => !i.product_type || !(Number(i.quantity) >= 1))) return fail("Pick a product and a quantity of at least 1 for every item.");
    setSaving(true);
    try {
      const saved = await api("delivery-contracts", {
        method: "POST",
        body: { ...form, chain: form.chain || null, notes: form.notes || null, items: form.items.map((i) => ({ ...i, quantity: Number(i.quantity) })) },
      });
      toast.success(`Contract ${saved.contract_no} was added. Approve it in Deliveries > Contracts to make the deliveries.`);
      onSaved();
    } catch (err) {
      fail(err.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal title={store ? `Contract for ${storeName(store)}` : "New delivery contract"} onClose={onClose} wide>
      <form onSubmit={save} className="p-5 flex flex-col gap-3.5" noValidate>
        {error && <div ref={errorRef} role="alert" className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[12px] text-red-700">{error}</div>}
        {current.length > 0 && (
          <div role="status" className="px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-[12px] text-amber-800">
            This store already has {current.length === 1 ? "a contract" : `${current.length} contracts`}:{" "}
            {current.map((c) => `${c.contract_no} (${c.status}, ${shortDate(c.start_date)} – ${shortDate(c.end_date)})`).join("; ")}.
            {" "}Dates that overlap would get two deliveries a day once both are approved.
          </div>
        )}
        {store ? (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label htmlFor="contract-branch" className={labelCls}>Branch name</label>
              <input id="contract-branch" name="branch_name" value={storeName(store)} readOnly aria-readonly="true" className={`${inputCls} cursor-default`} />
            </div>
            <div>
              <label htmlFor="contract-id" className={labelCls}>Contract ID</label>
              <input id="contract-id" name="contract_id" value={nextId || "Loading…"} readOnly aria-readonly="true" aria-describedby="contract-id-hint" className={`${inputCls} cursor-default font-mono`} />
              <p id="contract-id-hint" className="text-[11px] text-slate-400 mt-1">Taken when you save. If another contract for this customer is saved first, the last number moves up by one.</p>
            </div>
          </div>
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label htmlFor="contract-chain" className={labelCls}>Customer chain</label>
              <select id="contract-chain" name="chain" value={form.chain} onChange={(e) => pickChain(e.target.value)} className={inputCls}>
                <option value="">— Pick stores by hand —</option>
                {chains.map((c) => <option key={c} value={c}>{c}</option>)}
              </select>
            </div>
            <div>
              <label htmlFor="contract-name" className={labelCls}>Contract name<Req /></label>
              <input id="contract-name" name="name" required maxLength={150} value={form.name} onChange={(e) => set({ name: e.target.value })} placeholder="e.g. SM September" className={inputCls} />
            </div>
          </div>
        )}
        {!store && (
          <div>
            <div className={labelCls}>Stores<Req /></div>
            <StorePicker stores={stores} value={form.store_ids} onChange={(ids) => set({ store_ids: ids })} />
          </div>
        )}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label htmlFor="contract-from" className={labelCls}>From<Req /></label>
            <input id="contract-from" name="start_date" type="date" required min={todayStr()} max={yearAhead} value={form.start_date} onChange={(e) => set({ start_date: e.target.value })} className={inputCls} />
          </div>
          <div>
            <label htmlFor="contract-to" className={labelCls}>To<Req /></label>
            <input id="contract-to" name="end_date" type="date" required min={form.start_date} max={yearAhead} value={form.end_date} onChange={(e) => set({ end_date: e.target.value })} className={inputCls} />
          </div>
          <div>
            <label htmlFor="contract-deliver-by" className={labelCls}>Deliver by<Req /></label>
            <input id="contract-deliver-by" name="delivery_time" type="time" required value={form.delivery_time} onChange={(e) => set({ delivery_time: e.target.value })} className={inputCls} />
          </div>
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
          <button type="submit" disabled={saving} className={btnPrimary}>{saving ? "Saving…" : "Save contract"}</button>
        </div>
      </form>
    </Modal>
  );
}
