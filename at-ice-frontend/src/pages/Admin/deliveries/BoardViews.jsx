import { useState } from "react";
import { toast } from "../../../lib/toast";
import { PILL } from "../../../lib/statusTones";
import { api, DRIVER_STATUS, STATUS_STYLE, byDriverGroup, driverGroup, storeName, productLabel, PRODUCT_TYPES, inputCls, labelCls, btnPrimary, btnGhost } from "./utils";
import { ReassignedTag, StatusCell, Modal, DeliverByCell } from "./shared";
import { timingPill, deliverByValue, windowLabel } from "../../../lib/deliverBy";
import Req from "../../../components/Req";
import { useMaxQuantity } from "../../../lib/limits";
import { QTY_INPUT_PROPS, cleanQty } from "../../../lib/quantity";

const OPEN = ["pending", "queued", "in_transit"];

const GROUP_PILL = {
  timed_in: PILL.success,
  in_transit: PILL.progress,
  not_timed_in: PILL.warning,
  on_leave: PILL.info,
  off: PILL.danger,
};

// Status pill of a card in the Driver View
function cardPill(g, isToday) {
  if (g.key === "queued") return { cls: STATUS_STYLE.queued, label: "Queued" };
  if (g.key === "none") return { cls: STATUS_STYLE.pending, label: "No driver" };
  const st = DRIVER_STATUS[g.driver.status];
  if (!st || !(isToday || g.driver.status === "off")) return null;
  // "Timed out" or "No employee record" instead of a plain "Not timed in"
  const reason = g.driver.reason || "";
  const label = g.driver.status === "off" ? reason || "Off"
    : g.driver.status === "unavailable" && reason && !/not timed in/i.test(reason) ? reason.replace(/^No active employee record$/i, "No employee record")
      : st.label;
  // One color per status group, the same order as the filter: timed in green,
  // in transit cyan, not timed in amber, on leave blue, off (absent, timed
  // out, no record) rose
  return { pill: GROUP_PILL[driverGroup(g.driver)] || st.pill, label, title: reason };
}

// Driver View: a card per Driver; clicking one lists the stores they deliver
// to that day. "Queued" and "No driver" cards hold what still needs a driver.
// Cards go timed in, in transit, not timed in, off (byDriverGroup); the page's
// Driver status filter narrows them.
export function DriverView({ board, loading, onView, onEdit, onCancel }) {
  const [picked, setPicked] = useState(null); // driver id, "queued" or "none"
  const deliveries = board?.deliveries || [];
  const drivers = [...(board?.drivers || [])].sort(byDriverGroup);
  const isToday = board?.date === board?.today;
  const filtered = board?.driverFilter && board.driverFilter !== "all";

  const byDriver = (id) => deliveries.filter((d) => String(d.driver_id) === String(id));
  const queued = filtered ? [] : deliveries.filter((d) => d.status === "queued");
  const noDriver = filtered ? [] : deliveries.filter((d) => !d.driver_id && d.status === "pending");

  const groups = [
    ...drivers.map((dr) => ({ key: String(dr.id), driver: dr, list: byDriver(dr.id) })),
    ...(queued.length ? [{ key: "queued", title: "Queued", hint: "Waiting for a free driver (oldest order first)", list: queued }] : []),
    ...(noDriver.length ? [{ key: "none", title: "No driver", hint: "The store has no assigned driver. Edit to pick one.", list: noDriver }] : []),
  ];
  const current = groups.find((g) => g.key === String(picked)) || null;

  if (loading && !board) return <div className="p-6 text-[13px] text-slate-400">Loading…</div>;
  if (!(board?.drivers || []).length && !filtered) return <div className="p-6 text-[13px] text-slate-400">No drivers yet. Add a Driver in HRIS &gt; Employees.</div>;
  if (!groups.length) return <div className="p-6 text-[13px] text-slate-400">No driver has this status.</div>;

  return (
    <div className="p-[18px] flex flex-col gap-4">
      <div className="grid gap-2.5 grid-cols-[repeat(auto-fill,minmax(210px,1fr))]">
        {groups.map((g) => {
          const done = g.list.filter((d) => d.status === "delivered").length;
          const open = g.list.filter((d) => OPEN.includes(d.status)).length;
          const reassigned = g.list.filter((d) => d.reassigned_from).length;
          const pill = cardPill(g, isToday);
          const active = String(picked) === g.key;
          return (
            <button
              key={g.key}
              type="button"
              onClick={() => setPicked(active ? null : g.key)}
              aria-expanded={active}
              className={`driver-card text-left rounded-xl border p-3 transition-colors bg-white ${active ? "border-cyan-500 ring-2 ring-cyan-500/40" : "border-black/[0.09] hover:border-black/20"}`}
            >
              {/* Status on top at the right, the name on its own line below, so a
                  long name wraps instead of pushing the status (shared rule) */}
              <div className="driver-card-status">
                {pill ? (
                  <span className={`px-2 py-0.5 rounded-full border text-[10.5px] font-semibold whitespace-nowrap ${pill.pill || `${pill.cls.bg} ${pill.cls.text}`}`} title={pill.title}>
                    {pill.label}
                  </span>
                ) : <span aria-hidden="true" className="px-2 py-0.5 text-[10.5px] invisible">-</span>}
              </div>
              <div className="driver-card-name text-[13px] font-semibold text-slate-800">{g.driver ? g.driver.name : g.title}</div>
              <div className="text-[11px] text-slate-400">
                {g.driver ? [g.driver.employee_id, g.driver.vehicle_plate && `PN ${g.driver.vehicle_plate}`].filter(Boolean).join(" · ") || "Driver" : g.hint}
              </div>
              <div className="mt-2.5 flex items-center gap-3 text-[12px] text-slate-600">
                <span><b className="tabular-nums text-slate-800">{g.list.length}</b> stops</span>
                <span><b className="tabular-nums text-slate-800">{open}</b> open</span>
                <span><b className="tabular-nums text-slate-800">{done}</b> done</span>
              </div>
              {reassigned > 0 && (
                <div className="mt-1.5 text-[11px] font-medium text-violet-700">
                  {reassigned} {g.driver ? "reassigned to this driver" : "from a driver who is off"}
                </div>
              )}
              {g.driver && <div className="mt-1 text-[11px] text-slate-400">{g.driver.stores.length} regular {g.driver.stores.length === 1 ? "store" : "stores"}</div>}
            </button>
          );
        })}
      </div>

      {current && (
        <div className="rounded-xl border border-black/[0.09]">
          <div className="px-4 py-2.5 border-b border-black/[0.09] bg-slate-50 rounded-t-xl flex items-center justify-between gap-2">
            <span className="text-[12.5px] font-semibold text-slate-700">
              {current.driver ? `${current.driver.name}: stores to deliver` : current.title}
            </span>
            {current.driver?.status === "off" && (
              <span className="px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 text-[11px] font-semibold">{current.driver.reason || "Off"}</span>
            )}
          </div>
          {current.driver && <TruckStockLine batches={current.driver.truck_stock} />}
          <DeliveryRows list={current.list} showDriver={!current.driver} onView={onView} onEdit={onEdit} onCancel={onCancel} empty="No deliveries for this driver on this day." />
        </div>
      )}
    </div>
  );
}

// Ice still on the Driver's truck, oldest batch first (FIFO): the trucks are
// refrigerated and stay out, so what was not delivered goes out first next time.
function TruckStockLine({ batches = [] }) {
  const when = (v) => new Date(v).toLocaleString("en-PH", { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" });
  return (
    <div className="px-4 py-2.5 border-b border-black/[0.06] text-[12px]">
      <span className="font-medium text-slate-700">Ice on the truck: </span>
      {batches.length === 0 ? (
        <span className="text-slate-400">none left from earlier loads</span>
      ) : (
        <span className="text-slate-600">
          {batches.map((b) => `${b.quantity_left} × ${productLabel(b.product_type)} (loaded ${when(b.loaded_at)})`).join(" · ")}
          <span className="block text-[11px] text-slate-400 mt-0.5">The oldest load is delivered first.</span>
        </span>
      )}
    </div>
  );
}

// Store View: one row per store with a delivery that day.
// The toolbar (search, type, status) is on the page; `filtered` says it is narrowing the list.
export function StoreView({ board, loading, filtered, onView, onEdit, onCancel }) {
  const deliveries = board?.deliveries || [];
  const regular = {};
  (board?.drivers || []).forEach((dr) => dr.stores.forEach((s) => { regular[s.id] = dr.name; }));
  const list = [...deliveries].sort((a, b) => (a.customer?.name || "").localeCompare(b.customer?.name || ""));

  if (loading && !board) return <div className="p-6 text-[13px] text-slate-400">Loading…</div>;

  return (
    <div className="p-[18px] flex flex-col gap-3">
      <div className="overflow-x-auto lg:overflow-visible rounded-xl border border-black/[0.09]">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="text-left text-slate-400 text-[11px] uppercase tracking-widest">
              <th className="px-3 py-2.5 font-medium">Store</th>
              <th className="px-3 py-2.5 font-medium">Time window</th>
              <th className="px-3 py-2.5 font-medium">Driver</th>
              <th className="px-3 py-2.5 font-medium">Regular driver</th>
              <th className="px-3 py-2.5 font-medium">Status</th>
              <th className="px-3 py-2.5 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {list.length === 0 && <tr><td colSpan={6} className="px-3 py-8 text-center text-slate-400">{filtered ? "No store matches this filter." : "No deliveries on this day."}</td></tr>}
            {list.map((d) => (
              <tr key={d.id} className="border-t border-black/[0.06]">
                <td className="px-3 py-2.5">
                  <div className="text-slate-800">{storeName(d.customer)}</div>
                  <div className="text-[11px] text-slate-400">{[d.customer?.chain, d.contract?.contract_no, d.delivery_no].filter(Boolean).join(" · ")}</div>
                </td>
                <td className="px-3 py-2.5"><DeliverByCell delivery={d} /></td>
                <td className="px-3 py-2.5">
                  <div className="text-slate-700">{d.driver?.name || <span className="text-slate-300">—</span>}</div>
                  <ReassignedTag delivery={d} />
                </td>
                <td className="px-3 py-2.5 text-slate-500">{regular[d.customer_id] || "—"}</td>
                <td className="px-3 py-2.5"><StatusCell status={d.status} /></td>
                <td className="px-3 py-2.5"><RowActions d={d} onView={onView} onEdit={onEdit} onCancel={onCancel} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function DeliveryRows({ list, showDriver, onView, onEdit, onCancel, empty }) {
  if (!list.length) return <div className="px-4 py-6 text-center text-[13px] text-slate-400">{empty}</div>;
  return (
    <ul>
      {list.map((d) => (
        <li key={d.id} className="px-4 py-2.5 border-t first:border-t-0 border-black/[0.06] flex flex-wrap items-center gap-x-4 gap-y-1.5">
          <div className="min-w-[200px] flex-1">
            <div className="text-[13px] text-slate-800">{storeName(d.customer)}</div>
            <div className="text-[11px] text-slate-400">
              {[d.delivery_no, d.due_at && windowLabel(d.due_from, d.due_at), showDriver && d.driver?.name].filter(Boolean).join(" · ")}
            </div>
            <div className="text-[11px] text-slate-500 mt-0.5">
              {(d.items || []).map((it) => `${it.quantity_ordered} × ${productLabel(it.product_type)}`).join(", ")}
            </div>
          </div>
          <ReassignedTag delivery={d} />
          {timingPill(d.timing) && <span className={`px-1.5 py-px rounded-full text-[10px] font-semibold ${timingPill(d.timing).cls}`}>{timingPill(d.timing).label}</span>}
          <StatusCell status={d.status} />
          <RowActions d={d} onView={onView} onEdit={onEdit} onCancel={onCancel} />
        </li>
      ))}
    </ul>
  );
}

function RowActions({ d, onView, onEdit, onCancel }) {
  return (
    <div className="flex items-center gap-1.5 justify-end">
      <button type="button" onClick={() => onView(d)} className="px-2 py-1 rounded-md border border-black/[0.09] text-[11.5px] text-slate-600 hover:bg-slate-50">View</button>
      {["pending", "queued"].includes(d.status) && (
        <button type="button" onClick={() => onEdit(d)} className="px-2 py-1 rounded-md border border-black/[0.09] text-[11.5px] text-slate-600 hover:bg-slate-50">Edit</button>
      )}
      {OPEN.includes(d.status) && (
        <button data-no-confirm type="button" onClick={() => onCancel(d)} className="px-2 py-1 rounded-md border border-black/[0.09] text-[11.5px] text-red-600 hover:bg-red-50">Cancel</button>
      )}
    </div>
  );
}

// Admin edits a delivery that has not started: Driver, Deliver by time,
// quantities, notes. A new Deliver by time is sent to the store.
export function EditDeliveryModal({ delivery, drivers, isToday, onClose, onSaved }) {
  const [driverId, setDriverId] = useState(delivery.driver_id ? String(delivery.driver_id) : "");
  const maxQty = useMaxQuantity();
  const [items, setItems] = useState((delivery.items || []).map((it) => ({ product_type: it.product_type, quantity_ordered: String(it.quantity_ordered) })));
  const [notes, setNotes] = useState(delivery.notes || "");
  const [deliverBy, setDeliverBy] = useState(deliverByValue(delivery.due_at));
  const [deliverFrom, setDeliverFrom] = useState(deliverByValue(delivery.due_from));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  async function save(e) {
    e.preventDefault();
    if (items.some((it) => !it.product_type || !(Number(it.quantity_ordered) >= 1))) {
      setError("Pick a product and a quantity of at least 1 for every item.");
      return;
    }
    if (deliverFrom && deliverBy && deliverFrom >= deliverBy) {
      setError("The window must open before the Deliver by time.");
      return;
    }
    setSaving(true);
    setError("");
    try {
      await api(`deliveries/${delivery.id}`, {
        method: "PUT",
        body: { driver_id: driverId || null, notes: notes || null, ...(deliverBy ? { deliver_by: deliverBy, deliver_from: deliverFrom || null } : {}), items: items.map((it) => ({ ...it, quantity_ordered: Number(it.quantity_ordered) })) },
      });
      toast.success(`Delivery ${delivery.delivery_no} was updated.`);
      onSaved();
    } catch (err) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal title={`Edit ${delivery.delivery_no}`} onClose={onClose}>
      <form onSubmit={save} className="p-5 flex flex-col gap-3" noValidate>
        {error && <div className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[12px] text-red-700">{error}</div>}
        <div className="text-[13px] text-slate-700">{storeName(delivery.customer)}</div>
        <div>
          <label htmlFor="edit-delivery-driver" className={labelCls}>Driver</label>
          <select id="edit-delivery-driver" name="driver_id" value={driverId} onChange={(e) => setDriverId(e.target.value)} className={inputCls}>
            <option value="">No driver yet</option>
            {drivers.map((dr) => {
              const blocked = isToday && dr.status !== "available" && String(dr.id) !== String(delivery.driver_id);
              return (
                <option key={dr.id} value={dr.id} disabled={blocked}>
                  {dr.name}{isToday && dr.status ? ` (${dr.status === "off" ? dr.reason || "off" : DRIVER_STATUS[dr.status]?.label || dr.status})` : ""}
                </option>
              );
            })}
          </select>
          {isToday && <p className="text-[11px] text-slate-400 mt-1">Today only an available driver can be picked.</p>}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label htmlFor="edit-delivery-deliver-from" className={labelCls}>Deliver from</label>
            <input id="edit-delivery-deliver-from" name="deliver_from" type="time" max={deliverBy || undefined} value={deliverFrom} onChange={(e) => setDeliverFrom(e.target.value)} className={inputCls} />
          </div>
          <div>
            <label htmlFor="edit-delivery-deliver-by" className={labelCls}>Deliver by (latest){delivery.due_at && <Req />}</label>
            <input id="edit-delivery-deliver-by" name="deliver_by" type="time" required={!!delivery.due_at} value={deliverBy} onChange={(e) => setDeliverBy(e.target.value)} aria-describedby="edit-delivery-deliver-by-hint" className={inputCls} />
          </div>
          <p id="edit-delivery-deliver-by-hint" className="col-span-2 -mt-1.5 text-[11px] text-slate-400">The time window at the store. A new time is sent to the store.</p>
        </div>
        <div>
          <div className={labelCls}>Items</div>
          <div className="flex flex-col gap-2">
            {items.map((it, i) => (
              <div key={i} className="grid grid-cols-[minmax(0,1fr)_72px_auto] sm:grid-cols-[1fr_100px_auto] gap-2">
                <select name="product_type" value={it.product_type} aria-label={`Product ${i + 1}`} onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, product_type: e.target.value } : x)))} className={inputCls}>
                  <option value="">Select product…</option>
                  {PRODUCT_TYPES.map((p) => <option key={p.value} value={p.value} disabled={items.some((o, j) => j !== i && o.product_type === p.value)}>{p.label}</option>)}
                </select>
                <input {...QTY_INPUT_PROPS} name={`quantity-${i + 1}`} placeholder="Qty" aria-label={`Quantity ${i + 1}, at most ${maxQty}`} value={it.quantity_ordered} onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, quantity_ordered: cleanQty(e.target.value, maxQty) } : x)))} className={inputCls} />
                <button type="button" disabled={items.length === 1} onClick={() => setItems(items.filter((_, j) => j !== i))} className="px-2 rounded-md border border-black/[0.09] text-red-500 text-[12px] disabled:opacity-30">Remove</button>
              </div>
            ))}
          </div>
          {items.length < PRODUCT_TYPES.length && (
            <button type="button" onClick={() => setItems([...items, { product_type: "", quantity_ordered: "" }])} className="mt-2 text-[12px] font-medium text-cyan-700">+ Add item</button>
          )}
        </div>
        <div>
          <label htmlFor="edit-delivery-notes" className={labelCls}>Notes</label>
          <textarea id="edit-delivery-notes" name="notes" maxLength={500} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} className={inputCls} />
        </div>
        <div className="flex justify-end gap-2 pt-3 border-t border-black/[0.09]">
          <button type="button" onClick={onClose} className={btnGhost}>Close</button>
          <button type="submit" disabled={saving} className={btnPrimary}>{saving ? "Saving…" : "Save"}</button>
        </div>
      </form>
    </Modal>
  );
}
