import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import logo from "../../assets/logo.png";

const ChevronLeftIcon = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="w-5 h-5">
    <path d="M15 18l-6-6 6-6" strokeLinecap="round" strokeLinejoin="round" />
  </svg>
);

const PlusIcon = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="w-4 h-4">
    <path d="M12 5v14M5 12h14" strokeLinecap="round" />
  </svg>
);

const emptyForm = {
  supplier_code: "",
  name: "",
  contact_person: "",
  contact_number: "",
  email: "",
  address: "",
  city: "",
  province: "",
  notes: "",
};

export default function CheckerSupplierManagementPage() {
  const navigate = useNavigate();
  const token = localStorage.getItem("token");

  const [suppliers, setSuppliers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState(null); // null = creating new
  const [form, setForm] = useState(emptyForm);
  const [submitting, setSubmitting] = useState(false);
  const [deletingId, setDeletingId] = useState(null);

  useEffect(() => {
    fetchSuppliers();
  }, []);

  async function fetchSuppliers() {
    setLoading(true);
    setError("");
    try {
      const res = await fetch("/api/suppliers", {
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      });
      if (!res.ok) throw new Error("Failed to load suppliers.");
      const data = await res.json();
      setSuppliers(Array.isArray(data) ? data : data.data || []);
    } catch (e) {
      setError(e.message || "Something went wrong.");
    } finally {
      setLoading(false);
    }
  }

  function startCreate() {
    setEditingId(null);
    setForm(emptyForm);
    setShowForm(true);
  }

  function startEdit(s) {
    setEditingId(s.id);
    setForm({
      supplier_code: s.supplier_code || "",
      name: s.name || "",
      contact_person: s.contact_person || "",
      contact_number: s.contact_number || "",
      email: s.email || "",
      address: s.address || "",
      city: s.city || "",
      province: s.province || "",
      notes: s.notes || "",
    });
    setShowForm(true);
  }

  async function submit(e) {
    e.preventDefault();
    setSubmitting(true);
    setError("");
    try {
      const url = editingId ? `/api/suppliers/${editingId}` : "/api/suppliers";
      const method = editingId ? "PUT" : "POST";
      const res = await fetch(url, {
        method,
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify(form),
      });
      const data = await res.json();
      if (!res.ok) {
        // Surface Laravel validation messages (e.g. "The supplier code field is required.")
        const firstError =
          data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstError || data.message || "Could not save this supplier.");
      }
      setShowForm(false);
      setEditingId(null);
      setForm(emptyForm);
      fetchSuppliers();
    } catch (e) {
      setError(e.message || "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  async function remove(id) {
    setDeletingId(id);
    setError("");
    try {
      const res = await fetch(`/api/suppliers/${id}`, {
        method: "DELETE",
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.message || "Could not remove this supplier.");
      }
      fetchSuppliers();
    } catch (e) {
      setError(e.message || "Something went wrong.");
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <div className="min-h-screen bg-white">
      <div className="sticky top-0 z-10 bg-white border-b border-black/[0.09]">
        <div className="flex items-center gap-3 px-4 py-3">
          <button
            onClick={() => navigate("/checker/dashboard")}
            className="text-slate-500 hover:text-slate-800 transition-colors"
            aria-label="Back to dashboard"
          >
            <ChevronLeftIcon />
          </button>
          <img src={logo} alt="" className="w-7 h-7" />
          <div className="flex-1 min-w-0">
            <div className="text-[15px] font-semibold text-slate-800 leading-tight">
              Supplier Management
            </div>
            <div className="text-[12px] text-slate-400 leading-tight">
              {suppliers.length} supplier{suppliers.length === 1 ? "" : "s"}
            </div>
          </div>
          <button
            onClick={showForm && !editingId ? () => setShowForm(false) : startCreate}
            className="flex items-center gap-1 text-[13px] font-medium text-cyan-600 hover:text-cyan-700 transition-colors"
          >
            <PlusIcon />
            New
          </button>
        </div>

        {showForm && (
          <form onSubmit={submit} className="px-4 pb-4 flex flex-col gap-2.5 border-t border-black/[0.06] pt-3">
            <input
              value={form.supplier_code}
              onChange={(e) => setForm({ ...form, supplier_code: e.target.value })}
              placeholder="Supplier code (e.g. SUP-001)"
              required
              className="text-[13px] border border-black/[0.09] rounded-lg px-3 py-2 outline-none focus:border-cyan-400"
            />
            <input
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              placeholder="Supplier name"
              required
              className="text-[13px] border border-black/[0.09] rounded-lg px-3 py-2 outline-none focus:border-cyan-400"
            />
            <input
              value={form.contact_person}
              onChange={(e) => setForm({ ...form, contact_person: e.target.value })}
              placeholder="Contact person"
              className="text-[13px] border border-black/[0.09] rounded-lg px-3 py-2 outline-none focus:border-cyan-400"
            />
            <div className="flex gap-2.5">
              <input
                value={form.contact_number}
                onChange={(e) => setForm({ ...form, contact_number: e.target.value })}
                placeholder="Contact number"
                className="flex-1 text-[13px] border border-black/[0.09] rounded-lg px-3 py-2 outline-none focus:border-cyan-400"
              />
              <input
                value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })}
                type="email"
                placeholder="Email"
                className="flex-1 text-[13px] border border-black/[0.09] rounded-lg px-3 py-2 outline-none focus:border-cyan-400"
              />
            </div>
            <input
              value={form.address}
              onChange={(e) => setForm({ ...form, address: e.target.value })}
              placeholder="Address"
              className="text-[13px] border border-black/[0.09] rounded-lg px-3 py-2 outline-none focus:border-cyan-400"
            />
            <div className="flex gap-2.5">
              <input
                value={form.city}
                onChange={(e) => setForm({ ...form, city: e.target.value })}
                placeholder="City"
                className="flex-1 text-[13px] border border-black/[0.09] rounded-lg px-3 py-2 outline-none focus:border-cyan-400"
              />
              <input
                value={form.province}
                onChange={(e) => setForm({ ...form, province: e.target.value })}
                placeholder="Province"
                className="flex-1 text-[13px] border border-black/[0.09] rounded-lg px-3 py-2 outline-none focus:border-cyan-400"
              />
            </div>
            <textarea
              value={form.notes}
              onChange={(e) => setForm({ ...form, notes: e.target.value })}
              placeholder="Notes (optional)"
              rows={2}
              className="text-[13px] border border-black/[0.09] rounded-lg px-3 py-2 outline-none focus:border-cyan-400 resize-none"
            />
            {error && <div className="text-[12px] text-red-500">{error}</div>}
            <div className="flex gap-2">
              <button
                type="submit"
                disabled={submitting}
                className="flex-1 text-[13px] font-medium bg-cyan-500 hover:bg-cyan-600 text-white rounded-lg py-2 transition-colors disabled:opacity-50"
              >
                {submitting ? "Saving…" : editingId ? "Save changes" : "Add supplier"}
              </button>
              <button
                type="button"
                onClick={() => {
                  setShowForm(false);
                  setEditingId(null);
                  setError("");
                }}
                className="text-[13px] font-medium border border-black/[0.09] text-slate-500 hover:text-slate-800 rounded-lg px-4 py-2 transition-colors"
              >
                Cancel
              </button>
            </div>
          </form>
        )}
      </div>

      <div className="px-4 py-4 flex flex-col gap-2.5 max-w-2xl mx-auto">
        {!showForm && error && (
          <div className="text-[13px] text-red-500 text-center py-2">{error}</div>
        )}
        {loading && <div className="text-[13px] text-slate-400 py-8 text-center">Loading suppliers…</div>}
        {!loading && suppliers.length === 0 && (
          <div className="text-[13px] text-slate-400 py-8 text-center">No suppliers added yet.</div>
        )}

        {!loading &&
          suppliers.map((s) => (
            <div key={s.id} className="rounded-xl border border-black/[0.09] p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="text-[14px] font-medium text-slate-800 truncate">{s.name}</div>
                  <div className="text-[11px] text-slate-400 mt-0.5 font-mono">{s.supplier_code}</div>
                  {s.contact_person && (
                    <div className="text-[12px] text-slate-400 mt-1">{s.contact_person}</div>
                  )}
                  <div className="text-[12px] text-slate-500 mt-1 flex flex-wrap gap-x-3">
                    {s.contact_number && <span>{s.contact_number}</span>}
                    {s.email && <span>{s.email}</span>}
                  </div>
                  {(s.address || s.city || s.province) && (
                    <div className="text-[12px] text-slate-400 mt-1">
                      {[s.address, s.city, s.province].filter(Boolean).join(", ")}
                    </div>
                  )}
                  {s.notes && <div className="text-[12px] text-slate-400 mt-1 italic">{s.notes}</div>}
                </div>
                <div className="flex gap-2 shrink-0">
                  <button
                    onClick={() => startEdit(s)}
                    className="text-[12px] font-medium text-cyan-600 hover:text-cyan-700 transition-colors"
                  >
                    Edit
                  </button>
                  <button
                    onClick={() => remove(s.id)}
                    disabled={deletingId === s.id}
                    className="text-[12px] font-medium text-red-500 hover:text-red-600 transition-colors disabled:opacity-50"
                  >
                    Remove
                  </button>
                </div>
              </div>
            </div>
          ))}
      </div>
    </div>
  );
}