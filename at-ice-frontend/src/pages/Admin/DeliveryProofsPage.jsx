import { useEffect, useMemo, useState } from "react";
import AdminLayout from "../../components/AdminLayout";

// Photos are stored on Laravel's "public" disk (delivery_proofs/xxx.jpg),
// so they are served from the backend at /storage/... (needs `php artisan storage:link`).
const BACKEND_URL = import.meta.env.VITE_BACKEND_URL || "http://127.0.0.1:8000";
const photoUrl = (path) => (path ? `${BACKEND_URL}/storage/${path}` : null);

const PRODUCT_LABELS = {
  bags: "Bags",
  small_sack: "Small Sack",
  big_sack: "Big Sack",
};

const peso = (n) =>
  "₱" +
  Number(n || 0).toLocaleString("en-PH", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

const formatDateTime = (value) =>
  value
    ? new Date(value).toLocaleString("en-PH", {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
      })
    : "N/A";

const PAYMENT_BADGE = {
  paid: "bg-emerald-500/10 text-emerald-600 border-emerald-500/20",
  unpaid: "bg-amber-500/10 text-amber-600 border-amber-500/20",
  overdue: "bg-red-500/10 text-red-600 border-red-500/20",
};

export default function DeliveryProofsPage() {
  const [proofs, setProofs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [selected, setSelected] = useState(null);

  useEffect(() => {
    const load = async () => {
      try {
        const res = await fetch("/api/delivery-proofs", {
          headers: {
            Accept: "application/json",
            Authorization: `Bearer ${localStorage.getItem("token")}`,
          },
        });
        if (!res.ok) throw new Error(`Request failed (${res.status})`);
        setProofs(await res.json());
      } catch (err) {
        setError(err.message || "Could not load delivery proofs.");
      } finally {
        setLoading(false);
      }
    };
    load();
  }, []);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return proofs;
    return proofs.filter((p) =>
      [
        p.delivery?.delivery_no,
        p.delivery?.customer?.name,
        p.salesman?.name,
        p.receipt?.receipt_no,
      ]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(q))
    );
  }, [proofs, search]);

  return (
    <AdminLayout pageTitle="Delivery Proofs" breadcrumb="Delivery Proofs">
      <div className="flex flex-col gap-1">
        <input
          type="text"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search delivery no, customer, salesman, or receipt no"
          className="w-full max-w-md rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-cyan-500"
        />
        <p className="text-xs text-slate-500">
          Photo proof and quantity confirmed by Salesmen. Receipts are generated
          automatically when a proof is submitted.
        </p>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
            <tr>
              <th className="px-4 py-3 font-medium">Photo</th>
              <th className="px-4 py-3 font-medium">Delivery</th>
              <th className="px-4 py-3 font-medium">Customer</th>
              <th className="px-4 py-3 font-medium">Salesman</th>
              <th className="px-4 py-3 font-medium">Qty</th>
              <th className="px-4 py-3 font-medium">Confirmed</th>
              <th className="px-4 py-3 font-medium">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-slate-500">
                  Loading delivery proofs...
                </td>
              </tr>
            )}

            {!loading && error && (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-red-600">
                  {error}
                </td>
              </tr>
            )}

            {!loading && !error && filtered.length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-slate-500">
                  {proofs.length === 0
                    ? "No delivery proofs yet. They appear here once a Salesman confirms a delivery."
                    : "No delivery proofs match your search."}
                </td>
              </tr>
            )}

            {!loading &&
              !error &&
              filtered.map((p) => (
                <tr key={p.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-4 py-3">
                    {photoUrl(p.photo_path) ? (
                      <img
                        src={photoUrl(p.photo_path)}
                        alt={`Proof for ${p.delivery?.delivery_no || "delivery"}`}
                        className="h-12 w-12 cursor-pointer rounded-lg border border-slate-200 object-cover"
                        onClick={() => setSelected(p)}
                      />
                    ) : (
                      <span className="text-xs text-slate-400">No photo</span>
                    )}
                  </td>
                  <td className="px-4 py-3 font-medium text-slate-800">
                    {p.delivery?.delivery_no || "N/A"}
                  </td>
                  <td className="px-4 py-3 text-slate-700">
                    {p.delivery?.customer?.name || "Unknown"}
                  </td>
                  <td className="px-4 py-3 text-slate-700">{p.salesman?.name || "Unknown"}</td>
                  <td className="px-4 py-3 text-slate-700">{p.quantity}</td>
                  <td className="px-4 py-3 text-slate-600">{formatDateTime(p.confirmed_at)}</td>
                  <td className="px-4 py-3">
                    <button
                      onClick={() => setSelected(p)}
                      className="rounded-lg border border-cyan-500/30 px-3 py-1.5 text-xs font-medium text-cyan-700 hover:bg-cyan-50"
                    >
                      View
                    </button>
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>

      {selected && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
          onClick={() => setSelected(null)}
        >
          <div
            className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="mb-4 flex items-start justify-between">
              <div>
                <h2 className="text-lg font-semibold text-slate-800">
                  {selected.delivery?.delivery_no || "Delivery"} proof
                </h2>
                <p className="text-sm text-slate-500">
                  Confirmed {formatDateTime(selected.confirmed_at)} by{" "}
                  {selected.salesman?.name || "Unknown"}
                </p>
              </div>
              <button
                onClick={() => setSelected(null)}
                className="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100"
                aria-label="Close"
              >
                Close
              </button>
            </div>

            <div className="grid gap-5 md:grid-cols-2">
              <div>
                {photoUrl(selected.photo_path) ? (
                  <a href={photoUrl(selected.photo_path)} target="_blank" rel="noreferrer">
                    <img
                      src={photoUrl(selected.photo_path)}
                      alt="Delivery proof"
                      className="w-full rounded-xl border border-slate-200 object-cover"
                    />
                  </a>
                ) : (
                  <div className="flex h-48 items-center justify-center rounded-xl border border-dashed border-slate-300 text-sm text-slate-400">
                    No photo on file
                  </div>
                )}
              </div>

              <div className="space-y-3 text-sm">
                <div>
                  <p className="text-slate-500">Customer</p>
                  <p className="font-medium text-slate-800">
                    {selected.delivery?.customer?.name || "Unknown"}
                  </p>
                  <p className="text-slate-500">{selected.delivery?.customer?.address}</p>
                </div>
                <div>
                  <p className="text-slate-500">Total quantity delivered</p>
                  <p className="font-medium text-slate-800">{selected.quantity}</p>
                </div>
                {selected.notes && (
                  <div>
                    <p className="text-slate-500">Notes</p>
                    <p className="text-slate-800">{selected.notes}</p>
                  </div>
                )}
              </div>
            </div>

            <div className="mt-6">
              <div className="mb-2 flex items-center justify-between">
                <h3 className="font-medium text-slate-800">
                  Receipt {selected.receipt?.receipt_no || ""}
                </h3>
                {selected.receipt?.payment_status && (
                  <span
                    className={`rounded-full border px-2.5 py-0.5 text-xs font-medium ${
                      PAYMENT_BADGE[selected.receipt.payment_status] || ""
                    }`}
                  >
                    {selected.receipt.payment_status}
                  </span>
                )}
              </div>

              {selected.receipt?.items?.length ? (
                <div className="overflow-x-auto rounded-xl border border-slate-200">
                  <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 text-slate-600">
                      <tr>
                        <th className="px-3 py-2 font-medium">Product</th>
                        <th className="px-3 py-2 font-medium">Qty</th>
                        <th className="px-3 py-2 font-medium">Unit price</th>
                        <th className="px-3 py-2 text-right font-medium">Line total</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selected.receipt.items.map((item) => (
                        <tr key={item.id} className="border-t border-slate-100">
                          <td className="px-3 py-2">
                            {PRODUCT_LABELS[item.product_type] || item.product_type}
                          </td>
                          <td className="px-3 py-2">{item.quantity}</td>
                          <td className="px-3 py-2">{peso(item.unit_price)}</td>
                          <td className="px-3 py-2 text-right">{peso(item.line_total)}</td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot>
                      <tr className="border-t border-slate-200 bg-slate-50 font-medium">
                        <td className="px-3 py-2" colSpan={3}>
                          Total
                        </td>
                        <td className="px-3 py-2 text-right">
                          {peso(selected.receipt.total_amount)}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              ) : (
                <p className="text-sm text-slate-500">No receipt breakdown available.</p>
              )}
            </div>
          </div>
        </div>
      )}
    </AdminLayout>
  );
}