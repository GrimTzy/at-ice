import { useEffect, useState, useMemo } from "react";
import { useNavigate } from "react-router-dom";
import logo from "../../assets/logo.png";

/**
 * MERGED PAGE: Inventory Management + Low Stock Alert (per team decision —
 * both views read from the same underlying stock data, so they're now two
 * tabs on one page instead of two separate dashboard modules).
 *
 * ASSUMPTIONS (adjust if your actual schema differs):
 * - GET /api/products              -> [{ id, name, category, unit, current_stock, minimum_stock }]
 * - GET /api/inventory-alerts      -> [{ id, product: { id, name }, current_stock,
 *                                        minimum_stock, status: "active"|"resolved",
 *                                        created_at, resolved_at }]
 * - PUT /api/inventory-alerts/{id}/resolve
 * These are the same assumptions used in the original two separate pages —
 * not yet confirmed against the actual InventoryAlertController.php. If
 * fields don't match after testing, paste that controller and it'll get
 * corrected the same way SupplierController's mismatch was fixed.
 */

const ChevronLeftIcon = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="w-5 h-5">
    <path d="M15 18l-6-6 6-6" strokeLinecap="round" strokeLinejoin="round" />
  </svg>
);

const SearchIcon = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="w-4 h-4">
    <circle cx="11" cy="11" r="7" />
    <path d="M21 21l-4.3-4.3" strokeLinecap="round" />
  </svg>
);

function stockStatus(current, min) {
  if (current <= 0) return { label: "Out of stock", tone: "red" };
  if (current <= min) return { label: "Low stock", tone: "amber" };
  return { label: "In stock", tone: "default" };
}

const toneClasses = {
  red: { text: "text-red-500", badge: "bg-red-50 text-red-600 border-red-200" },
  amber: { text: "text-amber-600", badge: "bg-amber-50 text-amber-700 border-amber-200" },
  default: { text: "text-slate-800", badge: "bg-slate-50 text-slate-500 border-black/[0.09]" },
};

function fmtDate(d) {
  if (!d) return "";
  return new Date(d).toLocaleString("en-PH", {
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

const ALERT_TABS = ["Active", "Resolved", "All"];

export default function CheckerInventoryManagementPage() {
  const navigate = useNavigate();
  const token = localStorage.getItem("token");

  // Top-level view: "items" or "alerts"
  const [view, setView] = useState("items");

  // Items tab state
  const [products, setProducts] = useState([]);
  const [loadingProducts, setLoadingProducts] = useState(true);
  const [query, setQuery] = useState("");

  // Alerts tab state
  const [alerts, setAlerts] = useState([]);
  const [loadingAlerts, setLoadingAlerts] = useState(true);
  const [alertTab, setAlertTab] = useState("Active");
  const [resolving, setResolving] = useState(null);

  const [error, setError] = useState("");

  useEffect(() => {
    fetchProducts();
    fetchAlerts();
  }, []);

  async function fetchProducts() {
    setLoadingProducts(true);
    setError("");
    try {
      const res = await fetch("/api/products", {
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      });
      if (!res.ok) throw new Error("Failed to load inventory.");
      const data = await res.json();
      setProducts(Array.isArray(data) ? data : data.data || []);
    } catch (e) {
      setError(e.message || "Something went wrong.");
    } finally {
      setLoadingProducts(false);
    }
  }

  async function fetchAlerts() {
    setLoadingAlerts(true);
    try {
      const res = await fetch("/api/inventory-alerts", {
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      });
      if (!res.ok) return; // non-fatal — alerts tab will just show empty
      const data = await res.json();
      setAlerts(Array.isArray(data) ? data : data.data || []);
    } catch {
      /* non-fatal */
    } finally {
      setLoadingAlerts(false);
    }
  }

  async function resolveAlert(id) {
    setResolving(id);
    setError("");
    try {
      const res = await fetch(`/api/inventory-alerts/${id}/resolve`, {
        method: "PUT",
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || "Could not resolve this alert.");
      fetchAlerts();
    } catch (e) {
      setError(e.message || "Something went wrong.");
    } finally {
      setResolving(null);
    }
  }

  const filteredProducts = useMemo(() => {
    if (!query.trim()) return products;
    const q = query.toLowerCase();
    return products.filter(
      (p) => p.name?.toLowerCase().includes(q) || p.category?.toLowerCase().includes(q)
    );
  }, [products, query]);

  const counts = useMemo(() => {
    let low = 0, out = 0;
    for (const p of products) {
      const s = stockStatus(p.current_stock ?? 0, p.minimum_stock ?? 0);
      if (s.tone === "red") out++;
      else if (s.tone === "amber") low++;
    }
    return { low, out, total: products.length };
  }, [products]);

  const activeAlertCount = useMemo(
    () => alerts.filter((a) => a.status === "active").length,
    [alerts]
  );

  const filteredAlerts = alerts.filter((a) => {
    if (alertTab === "All") return true;
    return a.status === alertTab.toLowerCase();
  });

  return (
    <div className="min-h-screen bg-white">
      {/* Sticky top bar */}
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
              Inventory Management
            </div>
            <div className="text-[12px] text-slate-400 leading-tight">
              Supplies &amp; materials stock levels
            </div>
          </div>
        </div>

        {/* View tabs */}
        <div className="px-4 pb-3 flex gap-2">
          <button
            onClick={() => setView("items")}
            className={`text-[12px] font-medium px-3 py-1.5 rounded-full border transition-colors ${
              view === "items"
                ? "border-cyan-400 bg-cyan-50 text-cyan-700"
                : "border-black/[0.09] text-slate-500"
            }`}
          >
            All Items
          </button>
          <button
            onClick={() => setView("alerts")}
            className={`text-[12px] font-medium px-3 py-1.5 rounded-full border transition-colors ${
              view === "alerts"
                ? "border-cyan-400 bg-cyan-50 text-cyan-700"
                : "border-black/[0.09] text-slate-500"
            }`}
          >
            Low Stock Alert{activeAlertCount > 0 ? ` (${activeAlertCount})` : ""}
          </button>
        </div>

        {view === "items" && (
          <>
            <div className="px-4 pb-3 flex items-center gap-4 text-[12px] text-slate-500">
              <span>{counts.total} items</span>
              {counts.low > 0 && <span className="text-amber-600 font-medium">{counts.low} low stock</span>}
              {counts.out > 0 && <span className="text-red-500 font-medium">{counts.out} out of stock</span>}
            </div>
            <div className="px-4 pb-3">
              <div className="flex items-center gap-2 border border-black/[0.09] rounded-lg px-3 py-2">
                <SearchIcon />
                <input
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  placeholder="Search by name or category"
                  className="flex-1 text-[13px] outline-none placeholder:text-slate-400"
                />
              </div>
            </div>
          </>
        )}

        {view === "alerts" && (
          <div className="px-4 pb-3 flex gap-2">
            {ALERT_TABS.map((t) => (
              <button
                key={t}
                onClick={() => setAlertTab(t)}
                className={`text-[11px] font-medium px-2.5 py-1 rounded-full border transition-colors ${
                  alertTab === t
                    ? "border-slate-400 bg-slate-100 text-slate-700"
                    : "border-black/[0.09] text-slate-400"
                }`}
              >
                {t}
              </button>
            ))}
          </div>
        )}
      </div>

      {error && <div className="px-4 pt-3 text-[13px] text-red-500 text-center">{error}</div>}

      {/* ── ALL ITEMS VIEW ── */}
      {view === "items" && (
        <div className="px-4 py-4 flex flex-col gap-2.5 max-w-2xl mx-auto">
          {loadingProducts && (
            <div className="text-[13px] text-slate-400 py-8 text-center">Loading inventory…</div>
          )}
          {!loadingProducts && filteredProducts.length === 0 && (
            <div className="text-[13px] text-slate-400 py-8 text-center">
              {query ? "No items match your search." : "No inventory items yet."}
            </div>
          )}

          {!loadingProducts &&
            filteredProducts.map((p) => {
              const status = stockStatus(p.current_stock ?? 0, p.minimum_stock ?? 0);
              const tone = toneClasses[status.tone];
              return (
                <div
                  key={p.id}
                  className={`rounded-xl border p-4 flex items-center justify-between gap-4 ${
                    status.tone === "red" ? "border-red-200 bg-red-50/40" : "border-black/[0.09] bg-white"
                  }`}
                >
                  <div className="min-w-0">
                    <div className="text-[14px] font-medium text-slate-800 truncate">{p.name}</div>
                    <div className="text-[12px] text-slate-400 mt-0.5">
                      {p.category || "Uncategorized"}
                      {p.unit ? ` · ${p.unit}` : ""}
                    </div>
                  </div>
                  <div className="text-right shrink-0">
                    <div className={`text-[18px] font-semibold font-mono leading-none ${tone.text}`}>
                      {p.current_stock ?? 0}
                    </div>
                    <div className="text-[11px] text-slate-400 mt-1">min {p.minimum_stock ?? 0}</div>
                    <span className={`inline-block mt-1.5 text-[10px] font-medium px-2 py-0.5 rounded-full border ${tone.badge}`}>
                      {status.label}
                    </span>
                  </div>
                </div>
              );
            })}
        </div>
      )}

      {/* ── LOW STOCK ALERT VIEW ── */}
      {view === "alerts" && (
        <div className="px-4 py-4 flex flex-col gap-2.5 max-w-2xl mx-auto">
          {loadingAlerts && (
            <div className="text-[13px] text-slate-400 py-8 text-center">Loading alerts…</div>
          )}
          {!loadingAlerts && filteredAlerts.length === 0 && (
            <div className="text-[13px] text-slate-400 py-8 text-center">
              No {alertTab.toLowerCase() !== "all" ? alertTab.toLowerCase() : ""} alerts.
            </div>
          )}

          {!loadingAlerts &&
            filteredAlerts.map((a) => {
              const isActive = a.status === "active";
              return (
                <div
                  key={a.id}
                  className={`rounded-xl border p-4 flex items-center justify-between gap-4 ${
                    isActive ? "border-red-200 bg-red-50/40" : "border-black/[0.09] bg-white"
                  }`}
                >
                  <div className="min-w-0">
                    <div className="text-[14px] font-medium text-slate-800 truncate">
                      {a.product?.name || "Unknown product"}
                    </div>
                    <div className="text-[12px] text-slate-400 mt-0.5">
                      {isActive ? `Flagged ${fmtDate(a.created_at)}` : `Resolved ${fmtDate(a.resolved_at)}`}
                    </div>
                    <div className="text-[12px] text-slate-500 mt-1 font-mono">
                      {a.current_stock} on hand · min {a.minimum_stock}
                    </div>
                  </div>

                  {isActive ? (
                    <button
                      onClick={() => resolveAlert(a.id)}
                      disabled={resolving === a.id}
                      className="shrink-0 text-[13px] font-medium bg-cyan-500 hover:bg-cyan-600 text-white rounded-lg px-3 py-1.5 transition-colors disabled:opacity-50"
                    >
                      Resolve
                    </button>
                  ) : (
                    <span className="shrink-0 text-[10px] font-medium px-2 py-0.5 rounded-full border bg-green-50 text-green-700 border-green-200">
                      Resolved
                    </span>
                  )}
                </div>
              );
            })}
        </div>
      )}
    </div>
  );
}