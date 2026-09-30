import { useState, useEffect, useCallback } from "react";
import AdminLayout from "../../components/AdminLayout";

const API_BASE = "/api";

// Icons
const SearchIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>;
const PlusIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>;
const KeyIcon = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="7.5" cy="15.5" r="5.5" /><path d="M21 2l-9.6 9.6" /><path d="M15.5 7.5l3 3L22 7l-3-3" /></svg>;
const EditIcon = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" /><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" /></svg>;
const TrashIcon = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" /><path d="M10 11v6" /><path d="M14 11v6" /><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2" /></svg>;
const CopyIcon = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>;
const XIcon = () => <svg width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>;
const CheckIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12" /></svg>;

const MOP_LABELS = { cash: "Cash", gcash: "GCash", check: "Check", bank_transfer: "Bank Transfer" };

const EMPTY_FORM = {
  name: "", customer_code: "", branch_code: "", address: "", city: "",
  province: "", contact_person: "", contact_number: "", email: "",
  mop: "cash", notes: "", latitude: "", longitude: "",
};

// Latitude/longitude place the store's marker on the Driver GPS map.
// Stores without coordinates are not shown on the map.
function toCoord(value) {
  const n = parseFloat(String(value ?? "").trim());
  return Number.isFinite(n) ? n : null;
}

function token() { return localStorage.getItem("token") || ""; }
function authHeaders() {
  return { "Content-Type": "application/json", Accept: "application/json", Authorization: `Bearer ${token()}` };
}

export default function CustomersPage() {
  const [customers, setCustomers] = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState("");
  const [search, setSearch]       = useState("");

  const [showForm, setShowForm]   = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [form, setForm]           = useState(EMPTY_FORM);
  const [saving, setSaving]       = useState(false);
  const [formError, setFormError] = useState("");

  const [deactivateTarget, setDeactivateTarget] = useState(null);

  // Holds the just-created credentials so Admin can copy them - shown
  // once, never re-fetchable afterward (password is hashed server-side).
  const [newAccount, setNewAccount] = useState(null);
  const [creatingAccountFor, setCreatingAccountFor] = useState(null);

  const fetchCustomers = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const res = await fetch(`${API_BASE}/customers`, { headers: authHeaders() });
      if (!res.ok) throw new Error(`Request failed (${res.status})`);
      const data = await res.json();
      setCustomers(Array.isArray(data) ? data : []);
    } catch (err) {
      setError("Couldn't load customers. Check that the backend is running.");
      console.error("fetchCustomers error:", err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchCustomers(); }, [fetchCustomers]);

  function openAddForm() {
    setEditingId(null);
    setForm(EMPTY_FORM);
    setFormError("");
    setShowForm(true);
  }

  function openEditForm(customer) {
    setEditingId(customer.id);
    setForm({
      name: customer.name || "",
      customer_code: customer.customer_code || "",
      branch_code: customer.branch_code || "",
      address: customer.address || "",
      city: customer.city || "",
      province: customer.province || "",
      contact_person: customer.contact_person || "",
      contact_number: customer.contact_number || "",
      email: customer.email || "",
      mop: customer.mop || "cash",
      notes: customer.notes || "",
      latitude: customer.latitude ?? "",
      longitude: customer.longitude ?? "",
    });
    setFormError("");
    setShowForm(true);
  }

  async function submitForm(e) {
    e.preventDefault();
    setFormError("");

    const latitude  = toCoord(form.latitude);
    const longitude = toCoord(form.longitude);
    if ((latitude === null) !== (longitude === null)) {
      setFormError("Enter both latitude and longitude, or leave both blank.");
      return;
    }
    if (latitude !== null && (latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180)) {
      setFormError("Latitude must be between -90 and 90, and longitude between -180 and 180.");
      return;
    }

    setSaving(true);
    try {
      const payload = { ...form, latitude, longitude };
      const url = editingId ? `${API_BASE}/customers/${editingId}` : `${API_BASE}/customers`;
      const method = editingId ? "PUT" : "POST";
      const res = await fetch(url, { method, headers: authHeaders(), body: JSON.stringify(payload) });
      if (!res.ok) {
        const errData = await res.json().catch(() => null);
        throw new Error(errData?.message || "Failed to save customer.");
      }
      setShowForm(false);
      fetchCustomers();
    } catch (err) {
      setFormError(err.message);
    } finally {
      setSaving(false);
    }
  }

  async function confirmDeactivate() {
    if (!deactivateTarget) return;
    try {
      const res = await fetch(`${API_BASE}/customers/${deactivateTarget.id}`, {
        method: "DELETE", headers: authHeaders(),
      });
      if (!res.ok) throw new Error("Failed to deactivate customer.");
      setDeactivateTarget(null);
      fetchCustomers();
    } catch (err) {
      setError(err.message);
      setDeactivateTarget(null);
    }
  }

  async function createAccount(customer) {
    setCreatingAccountFor(customer.id);
    setError("");
    try {
      const res = await fetch(`${API_BASE}/customers/${customer.id}/create-account`, {
        method: "POST", headers: authHeaders(),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data?.message || "Failed to create login account.");
      setNewAccount({ customerName: customer.name, email: data.email, password: data.password });
      fetchCustomers();
    } catch (err) {
      setError(err.message);
    } finally {
      setCreatingAccountFor(null);
    }
  }

  function copyToClipboard(text) {
    navigator.clipboard?.writeText(text).catch(() => {});
  }

  const filtered = customers.filter((c) => {
    const q = search.trim().toLowerCase();
    if (!q) return true;
    const haystack = `${c.name} ${c.customer_code} ${c.branch_code ?? ""} ${c.city ?? ""}`.toLowerCase();
    return haystack.includes(q);
  });

  return (
    <AdminLayout pageTitle="Customers" breadcrumb="CRM / Customers">
      <div className="bg-white border border-black/[0.09] rounded-xl">
        <div className="px-[18px] py-3.5 border-b border-black/[0.09] flex items-center justify-between">
          <div>
            <h2 className="text-[15px] font-semibold text-slate-800">Customers</h2>
            <p className="text-[12px] text-slate-500 mt-0.5">
              Store branches and their contract details. Create a login here once a customer is ready for portal access.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <div className="flex items-center gap-2 bg-slate-50 border border-black/[0.09] rounded-lg px-3 py-1.5">
              <SearchIcon />
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search customer..."
                className="bg-transparent outline-none text-[13px] text-slate-700 placeholder:text-slate-400 w-44"
              />
            </div>
            <button
              onClick={openAddForm}
              className="flex items-center gap-1.5 px-3 py-1.5 bg-cyan-500 hover:bg-cyan-600 text-white text-[12px] font-medium rounded-lg transition-colors"
            >
              <PlusIcon /> Add Customer
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
                <th className="px-[18px] py-2.5 font-medium">Customer</th>
                <th className="px-[18px] py-2.5 font-medium">Location</th>
                <th className="px-[18px] py-2.5 font-medium">Contact</th>
                <th className="px-[18px] py-2.5 font-medium">MOP</th>
                <th className="px-[18px] py-2.5 font-medium">Portal Login</th>
                <th className="px-[18px] py-2.5 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading && (
                <tr><td colSpan={6} className="px-[18px] py-8 text-center text-slate-400 text-[13px]">Loading customers…</td></tr>
              )}
              {!loading && filtered.length === 0 && (
                <tr><td colSpan={6} className="px-[18px] py-8 text-center text-slate-400 text-[13px]">
                  {search ? "No customers match your search." : "No customers found."}
                </td></tr>
              )}
              {!loading && filtered.map((c) => (
                <tr key={c.id} className="border-t border-black/[0.06]">
                  <td className="px-[18px] py-2.5">
                    <div className="font-medium text-slate-800">{c.name}</div>
                    <div className="text-[11px] text-slate-400">{c.customer_code}{c.branch_code ? ` · ${c.branch_code}` : ""}</div>
                  </td>
                  <td className="px-[18px] py-2.5 text-slate-600">
                    {[c.city, c.province].filter(Boolean).join(", ") || <span className="text-slate-300">—</span>}
                    {(c.latitude == null || c.longitude == null) && (
                      <div className="text-[11px] text-amber-600">No map pin</div>
                    )}
                  </td>
                  <td className="px-[18px] py-2.5 text-slate-600">
                    {c.contact_person ? (
                      <>
                        <div>{c.contact_person}</div>
                        <div className="text-[11px] text-slate-400">{c.contact_number}</div>
                      </>
                    ) : <span className="text-slate-300">—</span>}
                  </td>
                  <td className="px-[18px] py-2.5 text-slate-600">{MOP_LABELS[c.mop] || c.mop}</td>
                  <td className="px-[18px] py-2.5">
                    {c.user ? (
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-cyan-50 text-cyan-700">
                        {c.user.email}
                      </span>
                    ) : (
                      <button
                        onClick={() => createAccount(c)}
                        disabled={creatingAccountFor === c.id}
                        className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-black/[0.09] text-slate-600 hover:bg-slate-50 text-[12px] disabled:opacity-50"
                      >
                        <KeyIcon />
                        {creatingAccountFor === c.id ? "Creating…" : "Create Login"}
                      </button>
                    )}
                  </td>
                  <td className="px-[18px] py-2.5">
                    <div className="flex items-center gap-1.5 justify-end">
                      <button
                        onClick={() => openEditForm(c)}
                        className="p-1.5 rounded-md border border-black/[0.09] text-slate-500 hover:bg-slate-50"
                        title="Edit"
                      >
                        <EditIcon />
                      </button>
                      <button
                        onClick={() => setDeactivateTarget(c)}
                        className="p-1.5 rounded-md border border-black/[0.09] text-red-500 hover:bg-red-50"
                        title="Deactivate"
                      >
                        <TrashIcon />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Add / Edit Customer modal */}
      {showForm && (
        <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 px-4">
          <div className="bg-white rounded-xl border border-black/[0.09] w-full max-w-lg max-h-[85vh] overflow-y-auto">
            <div className="px-5 py-4 border-b border-black/[0.09] flex items-center justify-between">
              <h3 className="text-[14px] font-semibold text-slate-800">
                {editingId ? "Edit Customer" : "Add Customer"}
              </h3>
              <button onClick={() => setShowForm(false)} className="text-slate-400 hover:text-slate-600">
                <XIcon />
              </button>
            </div>
            <form onSubmit={submitForm} className="p-5 flex flex-col gap-3">
              {formError && (
                <div className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[12px] text-red-700">
                  {formError}
                </div>
              )}
              <div className="grid grid-cols-2 gap-3">
                <Field label="Name *" value={form.name} onChange={(v) => setForm({ ...form, name: v })} required />
                <Field label="Customer Code *" value={form.customer_code} onChange={(v) => setForm({ ...form, customer_code: v })} required disabled={!!editingId} />
                <Field label="Branch Code" value={form.branch_code} onChange={(v) => setForm({ ...form, branch_code: v })} />
                <Field label="Contact Person" value={form.contact_person} onChange={(v) => setForm({ ...form, contact_person: v })} />
                <Field label="Contact Number" value={form.contact_number} onChange={(v) => setForm({ ...form, contact_number: v })} />
                <Field label="Contact Email" value={form.email} onChange={(v) => setForm({ ...form, email: v })} />
                <div className="col-span-2">
                  <Field label="Address" value={form.address} onChange={(v) => setForm({ ...form, address: v })} />
                </div>
                <Field label="City" value={form.city} onChange={(v) => setForm({ ...form, city: v })} />
                <Field label="Province" value={form.province} onChange={(v) => setForm({ ...form, province: v })} />
                <Field label="Latitude" value={form.latitude} onChange={(v) => setForm({ ...form, latitude: v })} placeholder="e.g. 14.8139" />
                <Field label="Longitude" value={form.longitude} onChange={(v) => setForm({ ...form, longitude: v })} placeholder="e.g. 121.0453" />
                <p className="col-span-2 -mt-1 text-[11px] text-slate-400">
                  Needed for the store marker on the Driver GPS map. In Google Maps, right-click the store and click the coordinates to copy them.
                </p>
                <div>
                  <label className="text-[11px] text-slate-500 mb-1 block">Mode of Payment *</label>
                  <select
                    value={form.mop}
                    onChange={(e) => setForm({ ...form, mop: e.target.value })}
                    className="w-full bg-slate-50 border border-black/[0.09] rounded-md px-2.5 py-1.5 text-[13px] outline-none focus:border-cyan-500"
                  >
                    {Object.entries(MOP_LABELS).map(([val, label]) => (
                      <option key={val} value={val}>{label}</option>
                    ))}
                  </select>
                </div>
                <div className="col-span-2">
                  <Field label="Notes" value={form.notes} onChange={(v) => setForm({ ...form, notes: v })} />
                </div>
              </div>
              <div className="flex justify-end gap-2 mt-2">
                <button type="button" onClick={() => setShowForm(false)} className="px-3.5 py-1.5 rounded-md border border-black/[0.09] text-slate-600 text-[12px]">
                  Cancel
                </button>
                <button type="submit" disabled={saving} className="px-3.5 py-1.5 rounded-md bg-cyan-500 hover:bg-cyan-600 text-white text-[12px] font-medium disabled:opacity-50">
                  {saving ? "Saving…" : editingId ? "Save Changes" : "Add Customer"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Deactivate confirmation */}
      {deactivateTarget && (
        <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 px-4">
          <div className="bg-white rounded-xl border border-black/[0.09] w-full max-w-sm p-5">
            <h3 className="text-[14px] font-semibold text-slate-800 mb-1.5">Deactivate customer?</h3>
            <p className="text-[12px] text-slate-500 mb-4">
              <span className="font-medium text-slate-700">{deactivateTarget.name}</span> will no longer appear in the active customer list. This can be reversed by a database administrator if needed.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setDeactivateTarget(null)} className="px-3.5 py-1.5 rounded-md border border-black/[0.09] text-slate-600 text-[12px]">
                Cancel
              </button>
              <button onClick={confirmDeactivate} className="px-3.5 py-1.5 rounded-md bg-red-500 hover:bg-red-600 text-white text-[12px] font-medium">
                Deactivate
              </button>
            </div>
          </div>
        </div>
      )}

      {/* New account credentials — shown once */}
      {newAccount && (
        <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 px-4">
          <div className="bg-white rounded-xl border border-black/[0.09] w-full max-w-sm p-5">
            <h3 className="text-[14px] font-semibold text-slate-800 mb-1">Login account created</h3>
            <p className="text-[12px] text-slate-500 mb-4">
              For <span className="font-medium text-slate-700">{newAccount.customerName}</span>. Copy these credentials now — the password won't be shown again. Give them to the customer directly.
            </p>
            <div className="flex flex-col gap-2 mb-4">
              <CredentialRow label="Email" value={newAccount.email} onCopy={copyToClipboard} />
              <CredentialRow label="Password" value={newAccount.password} onCopy={copyToClipboard} />
            </div>
            <button
              onClick={() => setNewAccount(null)}
              className="w-full px-3.5 py-2 rounded-md bg-cyan-500 hover:bg-cyan-600 text-white text-[12px] font-medium flex items-center justify-center gap-1.5"
            >
              <CheckIcon /> Done
            </button>
          </div>
        </div>
      )}
    </AdminLayout>
  );
}

function Field({ label, value, onChange, required, disabled, placeholder }) {
  return (
    <div>
      <label className="text-[11px] text-slate-500 mb-1 block">{label}</label>
      <input
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        required={required}
        disabled={disabled}
        placeholder={placeholder}
        className="w-full bg-slate-50 border border-black/[0.09] rounded-md px-2.5 py-1.5 text-[13px] outline-none focus:border-cyan-500 disabled:opacity-60"
      />
    </div>
  );
}

function CredentialRow({ label, value, onCopy }) {
  return (
    <div className="flex items-center justify-between bg-slate-50 border border-black/[0.09] rounded-md px-3 py-2">
      <div>
        <div className="text-[10px] text-slate-400 uppercase tracking-wide">{label}</div>
        <div className="text-[13px] font-mono text-slate-800">{value}</div>
      </div>
      <button onClick={() => onCopy(value)} className="text-slate-400 hover:text-cyan-600 p-1">
        <CopyIcon />
      </button>
    </div>
  );
}