import { useState, useEffect, useCallback } from "react";
import AdminLayout from "../../components/AdminLayout";

const API_BASE = "/api";

// Icons
const SearchIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>;
const PlusIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>;
const XIcon = () => <svg width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>;
const TrashIcon = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" /><path d="M10 11v6" /><path d="M14 11v6" /><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2" /></svg>;
const EyeIcon = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>;
const BanIcon = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" /><line x1="4.93" y1="4.93" x2="19.07" y2="19.07" /></svg>;

const STATUS_STYLE = {
  pending:    { bg: "bg-amber-50",  text: "text-amber-700"  },
  in_transit: { bg: "bg-blue-50",   text: "text-blue-700"   },
  delivered:  { bg: "bg-green-50",  text: "text-green-700"  },
  cancelled:  { bg: "bg-slate-100", text: "text-slate-500"  },
};

// Ice products delivered to customers. These are NOT inventory items
// (ice is perishable and goes straight to the truck), so they are a fixed
// list, the same three types used by Customer Prices and Salesman confirmation.
const PRODUCT_TYPES = [
  { value: "bags",       label: "Bags (5kg)" },
  { value: "small_sack", label: "Small Sack (30kg)" },
  { value: "big_sack",   label: "Big Sack (50kg)" },
];
const productLabel = (type) => PRODUCT_TYPES.find((p) => p.value === type)?.label || type;

const EMPTY_ITEM = { product_type: "", quantity_ordered: "" };
// Today as YYYY-MM-DD in the browser's local time (toISOString would be UTC).
const todayStr = () => {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

// A function, not a constant, so the default date is today even if the page
// stays open past midnight.
const emptyForm = () => ({
  driver_id: "", customer_id: "", scheduled_date: todayStr(), notes: "",
  items: [{ ...EMPTY_ITEM }],
});

function token() { return localStorage.getItem("token") || ""; }
function authHeaders() {
  return { "Content-Type": "application/json", Accept: "application/json", Authorization: `Bearer ${token()}` };
}

export default function DeliveriesPage() {
  const [deliveries, setDeliveries] = useState([]);
  const [drivers, setDrivers]       = useState([]);
  const [customers, setCustomers]   = useState([]);

  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState("");
  const [search, setSearch]   = useState("");

  const [showForm, setShowForm]     = useState(false);
  const [form, setForm]             = useState(emptyForm);
  const [saving, setSaving]         = useState(false);
  const [formError, setFormError]   = useState("");

  const [viewing, setViewing]       = useState(null);
  const [cancelTarget, setCancelTarget] = useState(null);

  const fetchAll = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [dRes, empRes, cRes] = await Promise.all([
        fetch(`${API_BASE}/deliveries`, { headers: authHeaders() }),
        fetch(`${API_BASE}/employees-summary`, { headers: authHeaders() }),
        fetch(`${API_BASE}/customers`, { headers: authHeaders() }),
      ]);
      if (!dRes.ok) throw new Error(`Deliveries request failed (${dRes.status})`);

      const [dData, empData, cData] = await Promise.all([
        dRes.json(), empRes.json(), cRes.json(),
      ]);

      setDeliveries(Array.isArray(dData) ? dData : []);
      // Drivers are Employees whose linked user has role "driver" — see
      // EmployeeController@index()'s widened query.
      setDrivers((Array.isArray(empData) ? empData : []).filter((e) => e.user?.role === "driver"));
      setCustomers(Array.isArray(cData) ? cData : []);
    } catch (err) {
      setError("Couldn't load deliveries. Check that the backend is running.");
      console.error("fetchAll error:", err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchAll(); }, [fetchAll]);

  function openAddForm() {
    setForm(emptyForm());
    setFormError("");
    setShowForm(true);
  }

  function updateItem(index, field, value) {
    setForm((f) => {
      const items = [...f.items];
      items[index] = { ...items[index], [field]: value };
      return { ...f, items };
    });
  }

  function addItemRow() {
    setForm((f) => ({ ...f, items: [...f.items, { ...EMPTY_ITEM }] }));
  }

  function removeItemRow(index) {
    setForm((f) => ({ ...f, items: f.items.filter((_, i) => i !== index) }));
  }

  async function submitForm(e) {
    e.preventDefault();
    setFormError("");

    if (form.scheduled_date < todayStr()) {
      setFormError("Scheduled date cannot be in the past.");
      return;
    }

    // Each product type can only appear once per delivery
    const types = form.items.map((it) => it.product_type);
    if (new Set(types).size !== types.length) {
      setFormError("Each product can only be added once. Combine the quantities in one row.");
      return;
    }

    setSaving(true);
    try {
      // Unit prices are not sent: the backend takes them from Customer Prices
      // for the selected customer, the same prices the Salesman receipt uses.
      const payload = {
        driver_id: form.driver_id,
        customer_id: form.customer_id,
        delivery_type: "outbound",
        scheduled_date: form.scheduled_date,
        notes: form.notes || null,
        items: form.items.map((it) => ({
          product_type: it.product_type,
          quantity_ordered: Number(it.quantity_ordered),
        })),
      };
      const res = await fetch(`${API_BASE}/deliveries`, {
        method: "POST", headers: authHeaders(), body: JSON.stringify(payload),
      });
      if (!res.ok) {
        const errData = await res.json().catch(() => null);
        throw new Error(errData?.message || "Failed to create delivery.");
      }
      setShowForm(false);
      fetchAll();
    } catch (err) {
      setFormError(err.message);
    } finally {
      setSaving(false);
    }
  }

  async function confirmCancel() {
    if (!cancelTarget) return;
    try {
      const res = await fetch(`${API_BASE}/deliveries/${cancelTarget.id}/cancel`, {
        method: "PUT", headers: authHeaders(),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok) throw new Error(data?.message || "Failed to cancel delivery.");
      setCancelTarget(null);
      fetchAll();
    } catch (err) {
      setError(err.message);
      setCancelTarget(null);
    }
  }

  const filtered = deliveries.filter((d) => {
    const q = search.trim().toLowerCase();
    if (!q) return true;
    const haystack = `${d.delivery_no} ${d.customer?.name ?? ""} ${d.driver?.name ?? ""}`.toLowerCase();
    return haystack.includes(q);
  });

  return (
    <AdminLayout pageTitle="Deliveries" breadcrumb="Delivery / Deliveries">
      <div className="bg-white border border-black/[0.09] rounded-xl">
        <div className="px-[18px] py-3.5 border-b border-black/[0.09] flex items-center justify-between">
          <div>
            <h2 className="text-[15px] font-semibold text-slate-800">Deliveries</h2>
            <p className="text-[12px] text-slate-500 mt-0.5">
              Assign a driver, customer, and line items. Salesman confirms with photo proof; Driver updates status en route.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <div className="flex items-center gap-2 bg-slate-50 border border-black/[0.09] rounded-lg px-3 py-1.5">
              <SearchIcon />
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search delivery..."
                className="bg-transparent outline-none text-[13px] text-slate-700 placeholder:text-slate-400 w-44"
              />
            </div>
            <button
              onClick={openAddForm}
              className="flex items-center gap-1.5 px-3 py-1.5 bg-cyan-500 hover:bg-cyan-600 text-white text-[12px] font-medium rounded-lg transition-colors"
            >
              <PlusIcon /> New Delivery
            </button>
          </div>
        </div>

        {error && (
          <div className="mx-[18px] mt-3 px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[12px] text-red-700">
            {error}
          </div>
        )}

        <div className="overflow-x-auto">
          <table className="w-full text-[13px]">
            <thead>
              <tr className="text-left text-slate-400 text-[11px] uppercase tracking-widest">
                <th className="px-[18px] py-2.5 font-medium">Delivery No.</th>
                <th className="px-[18px] py-2.5 font-medium">Customer</th>
                <th className="px-[18px] py-2.5 font-medium">Driver</th>
                <th className="px-[18px] py-2.5 font-medium">Scheduled</th>
                <th className="px-[18px] py-2.5 font-medium">Status</th>
                <th className="px-[18px] py-2.5 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading && (
                <tr><td colSpan={6} className="px-[18px] py-8 text-center text-slate-400 text-[13px]">Loading deliveries…</td></tr>
              )}
              {!loading && filtered.length === 0 && (
                <tr><td colSpan={6} className="px-[18px] py-8 text-center text-slate-400 text-[13px]">
                  {search ? "No deliveries match your search." : "No deliveries yet."}
                </td></tr>
              )}
              {!loading && filtered.map((d) => {
                const style = STATUS_STYLE[d.status] || STATUS_STYLE.pending;
                return (
                  <tr key={d.id} className="border-t border-black/[0.06]">
                    <td className="px-[18px] py-2.5 font-mono text-slate-700">{d.delivery_no}</td>
                    <td className="px-[18px] py-2.5 text-slate-700">{d.customer?.name || <span className="text-slate-300">—</span>}</td>
                    <td className="px-[18px] py-2.5 text-slate-700">{d.driver?.name || <span className="text-slate-300">—</span>}</td>
                    <td className="px-[18px] py-2.5 text-slate-600">
                      {d.scheduled_date ? new Date(d.scheduled_date).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" }) : "—"}
                    </td>
                    <td className="px-[18px] py-2.5">
                      <span className={`inline-block px-2 py-0.5 rounded-full text-[11px] font-medium capitalize ${style.bg} ${style.text}`}>
                        {d.status?.replace("_", " ")}
                      </span>
                    </td>
                    <td className="px-[18px] py-2.5">
                      <div className="flex items-center gap-1.5 justify-end">
                        <button onClick={() => setViewing(d)} className="p-1.5 rounded-md border border-black/[0.09] text-slate-500 hover:bg-slate-50" title="View">
                          <EyeIcon />
                        </button>
                        {d.status !== "delivered" && d.status !== "cancelled" && (
                          <button onClick={() => setCancelTarget(d)} className="p-1.5 rounded-md border border-black/[0.09] text-red-500 hover:bg-red-50" title="Cancel">
                            <BanIcon />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>

      {/* New Delivery modal */}
      {showForm && (
        <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 px-4">
          <div className="bg-white rounded-xl border border-black/[0.09] w-full max-w-2xl max-h-[85vh] overflow-y-auto">
            <div className="px-5 py-4 border-b border-black/[0.09] flex items-center justify-between">
              <h3 className="text-[14px] font-semibold text-slate-800">New Delivery</h3>
              <button onClick={() => setShowForm(false)} className="text-slate-400 hover:text-slate-600">
                <XIcon />
              </button>
            </div>
            <form onSubmit={submitForm} className="p-5 flex flex-col gap-4">
              {formError && (
                <div className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[12px] text-red-700">
                  {formError}
                </div>
              )}

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-[11px] text-slate-500 mb-1 block">Driver *</label>
                  <select
                    value={form.driver_id}
                    onChange={(e) => setForm({ ...form, driver_id: e.target.value })}
                    required
                    className="w-full bg-slate-50 border border-black/[0.09] rounded-md px-2.5 py-1.5 text-[13px] outline-none focus:border-cyan-500"
                  >
                    <option value="">Select driver…</option>
                    {drivers.map((d) => (
                      <option key={d.user?.id} value={d.user?.id}>{d.first_name} {d.last_name}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="text-[11px] text-slate-500 mb-1 block">Customer *</label>
                  <select
                    value={form.customer_id}
                    onChange={(e) => setForm({ ...form, customer_id: e.target.value })}
                    required
                    className="w-full bg-slate-50 border border-black/[0.09] rounded-md px-2.5 py-1.5 text-[13px] outline-none focus:border-cyan-500"
                  >
                    <option value="">Select customer…</option>
                    {customers.map((c) => (
                      <option key={c.id} value={c.id}>{c.name}{c.branch_code ? ` · ${c.branch_code}` : ""}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="text-[11px] text-slate-500 mb-1 block">Scheduled Date *</label>
                  <input
                    type="date"
                    min={todayStr()}
                    value={form.scheduled_date}
                    onChange={(e) => setForm({ ...form, scheduled_date: e.target.value })}
                    required
                    className="w-full bg-slate-50 border border-black/[0.09] rounded-md px-2.5 py-1.5 text-[13px] outline-none focus:border-cyan-500"
                  />
                </div>
                <div>
                  <label className="text-[11px] text-slate-500 mb-1 block">Notes</label>
                  <input
                    type="text"
                    value={form.notes}
                    onChange={(e) => setForm({ ...form, notes: e.target.value })}
                    className="w-full bg-slate-50 border border-black/[0.09] rounded-md px-2.5 py-1.5 text-[13px] outline-none focus:border-cyan-500"
                  />
                </div>
              </div>

              <div>
                <div className="flex items-center justify-between mb-2">
                  <label className="text-[11px] text-slate-500">Items *</label>
                  <button type="button" onClick={addItemRow} className="text-[11px] text-cyan-600 font-medium flex items-center gap-1">
                    <PlusIcon /> Add item
                  </button>
                </div>
                <div className="flex flex-col gap-2">
                  {form.items.map((item, idx) => (
                    <div key={idx} className="grid grid-cols-[1fr_110px_auto] gap-2 items-center">
                      <select
                        value={item.product_type}
                        onChange={(e) => updateItem(idx, "product_type", e.target.value)}
                        required
                        className="bg-slate-50 border border-black/[0.09] rounded-md px-2.5 py-1.5 text-[13px] outline-none focus:border-cyan-500"
                      >
                        <option value="">Select product…</option>
                        {PRODUCT_TYPES.map((p) => (
                          <option key={p.value} value={p.value}>{p.label}</option>
                        ))}
                      </select>
                      <input
                        type="number" min="1" placeholder="Qty"
                        value={item.quantity_ordered}
                        onChange={(e) => updateItem(idx, "quantity_ordered", e.target.value)}
                        required
                        className="bg-slate-50 border border-black/[0.09] rounded-md px-2.5 py-1.5 text-[13px] outline-none focus:border-cyan-500"
                      />
                      <button
                        type="button"
                        onClick={() => removeItemRow(idx)}
                        disabled={form.items.length === 1}
                        className="p-1.5 rounded-md border border-black/[0.09] text-red-500 hover:bg-red-50 disabled:opacity-30 disabled:cursor-not-allowed"
                      >
                        <TrashIcon />
                      </button>
                    </div>
                  ))}
                </div>
                <p className="text-[11px] text-slate-400 mt-2">
                  Unit prices are taken from Customer Prices for the selected customer.
                </p>
              </div>

              <div className="flex justify-end gap-2 mt-2">
                <button type="button" onClick={() => setShowForm(false)} className="px-3.5 py-1.5 rounded-md border border-black/[0.09] text-slate-600 text-[12px]">
                  Cancel
                </button>
                <button type="submit" disabled={saving} className="px-3.5 py-1.5 rounded-md bg-cyan-500 hover:bg-cyan-600 text-white text-[12px] font-medium disabled:opacity-50">
                  {saving ? "Creating…" : "Create Delivery"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* View delivery modal */}
      {viewing && (
        <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 px-4">
          <div className="bg-white rounded-xl border border-black/[0.09] w-full max-w-lg max-h-[85vh] overflow-y-auto">
            <div className="px-5 py-4 border-b border-black/[0.09] flex items-center justify-between">
              <h3 className="text-[14px] font-semibold text-slate-800">{viewing.delivery_no}</h3>
              <button onClick={() => setViewing(null)} className="text-slate-400 hover:text-slate-600">
                <XIcon />
              </button>
            </div>
            <div className="p-5 flex flex-col gap-3 text-[13px]">
              <Row label="Customer" value={viewing.customer?.name} />
              <Row label="Driver" value={viewing.driver?.name} />
              <Row label="Scheduled" value={viewing.scheduled_date ? new Date(viewing.scheduled_date).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" }) : "—"} />
              <Row label="Status" value={<span className="capitalize">{viewing.status?.replace("_", " ")}</span>} />
              <Row label="Notes" value={viewing.notes || "—"} />
              <div>
                <div className="text-[11px] text-slate-400 uppercase tracking-wide mb-1.5">Items</div>
                <div className="flex flex-col gap-1.5">
                  {(viewing.items || []).map((it) => (
                    <div key={it.id} className="flex items-center justify-between bg-slate-50 border border-black/[0.09] rounded-md px-3 py-2">
                      <span className="text-slate-700">{it.product_type ? productLabel(it.product_type) : it.product?.name}</span>
                      <span className="text-slate-500 font-mono">{it.quantity_ordered} × ₱{Number(it.unit_price).toFixed(2)}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Cancel confirmation */}
      {cancelTarget && (
        <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 px-4">
          <div className="bg-white rounded-xl border border-black/[0.09] w-full max-w-sm p-5">
            <h3 className="text-[14px] font-semibold text-slate-800 mb-1.5">Cancel delivery?</h3>
            <p className="text-[12px] text-slate-500 mb-4">
              <span className="font-medium text-slate-700">{cancelTarget.delivery_no}</span> will be marked cancelled and removed from the driver's active list.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setCancelTarget(null)} className="px-3.5 py-1.5 rounded-md border border-black/[0.09] text-slate-600 text-[12px]">
                Keep it
              </button>
              <button onClick={confirmCancel} className="px-3.5 py-1.5 rounded-md bg-red-500 hover:bg-red-600 text-white text-[12px] font-medium">
                Cancel Delivery
              </button>
            </div>
          </div>
        </div>
      )}
    </AdminLayout>
  );
}

function Row({ label, value }) {
  return (
    <div className="flex items-start justify-between gap-3">
      <span className="text-[11px] text-slate-400 uppercase tracking-wide flex-shrink-0">{label}</span>
      <span className="text-slate-700 text-right">{value}</span>
    </div>
  );
}