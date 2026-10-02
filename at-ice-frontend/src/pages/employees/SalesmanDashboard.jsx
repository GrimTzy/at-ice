import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import logo from "../../assets/logo.png";

// Icons 
const MapPinIcon = () => (
  <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
    <circle cx="12" cy="10" r="3" />
  </svg>
);
const UserIcon = () => (
  <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
    <circle cx="12" cy="7" r="4" />
  </svg>
);
const PackageIcon = () => (
  <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
    <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
    <line x1="12" y1="22.08" x2="12" y2="12" />
  </svg>
);
const ChevronRightIcon = () => (
  <svg width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <polyline points="9 18 15 12 9 6" />
  </svg>
);
const LogOutIcon = () => (
  <svg width="17" height="17" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
    <polyline points="16 17 21 12 16 7" />
    <line x1="21" y1="12" x2="9" y2="12" />
  </svg>
);
const ClockIcon = () => (
  <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <circle cx="12" cy="12" r="10" />
    <polyline points="12 6 12 12 16 14" />
  </svg>
);
const CameraIcon = () => (
  <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
    <circle cx="12" cy="13" r="4" />
  </svg>
);

// Helpers
const token = () => localStorage.getItem("token") || "";

const STATUS_CONFIG = {
  pending:    { bg: "bg-amber-50",  text: "text-amber-600",  dot: "bg-amber-400",  label: "Pending"    },
  in_transit: { bg: "bg-blue-50",   text: "text-blue-600",   dot: "bg-blue-500",   label: "In Transit" },
  delivered:  { bg: "bg-green-50",  text: "text-green-600",  dot: "bg-green-500",  label: "Delivered"  },
  cancelled:  { bg: "bg-slate-100", text: "text-slate-400",  dot: "bg-slate-300",  label: "Cancelled"  },
};

const FILTERS = ["All", "Pending", "In Transit", "Delivered"];

export default function SalesmanDashboard() {
  const navigate = useNavigate();
  const [deliveries, setDeliveries] = useState([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");
  const [filter, setFilter] = useState("All");
  const [expanded, setExpanded] = useState(null);

  const fetchDeliveries = async () => {
    setLoading(true);
    setLoadError("");
    try {
      const res = await fetch("/api/my-deliveries", {
        headers: { Accept: "application/json", Authorization: `Bearer ${token()}` },
      });
      if (!res.ok) throw new Error("Failed to load deliveries.");
      const data = await res.json();
      setDeliveries(Array.isArray(data) ? data : data.data || []);
    } catch (err) {
      setLoadError(err.message || "Could not load deliveries.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchDeliveries();
  }, []);

  // Sign out: clear the session first (same as the Checker and Driver pages).
  // Previously this only navigated to the login page and kept the token.
  const handleLogout = () => {
    ["token", "role", "sub_role", "user"].forEach((k) => localStorage.removeItem(k));
    navigate("/salesman/login");
  };

  const statusLabel = (s) => STATUS_CONFIG[s]?.label || s;
  const visible =
    filter === "All"
      ? deliveries
      : deliveries.filter((d) => statusLabel(d.status) === filter);

  const summary = [
    { label: "Total", value: deliveries.length, color: "text-slate-800" },
    { label: "Pending", value: deliveries.filter((d) => d.status === "pending").length, color: "text-amber-500" },
    { label: "In Transit", value: deliveries.filter((d) => d.status === "in_transit").length, color: "text-blue-500" },
    { label: "Delivered", value: deliveries.filter((d) => d.status === "delivered").length, color: "text-green-500" },
  ];

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col font-sans">

      {/* ── Top Bar ── */}
      <header className="bg-white border-b border-black/[0.08] px-5 pt-10 pb-4 flex-shrink-0 sticky top-0 z-20">
        <div className="flex items-center justify-between mb-1">
          <div className="flex items-center gap-2">
            <div className="w-7 h-7 bg-cyan-500/10 border border-cyan-500/20 rounded-lg flex items-center justify-center overflow-hidden">
             <img src={logo} alt="Bella Erin logo" className="w-full h-full object-contain p-1" />
            </div>
            <div>
              <div className="text-[13px] font-semibold text-slate-800 leading-none">
                {JSON.parse(localStorage.getItem("user") || "{}")?.name || "Salesman"}
              </div>
              <div className="text-[10px] text-slate-400 tracking-wide">Salesman</div>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <button
              onClick={() => navigate("/my-leave")}
              className="px-3 py-1.5 bg-slate-100 border border-black/[0.08] rounded-lg text-slate-600 text-[11px] font-medium active:bg-slate-200 transition-colors cursor-pointer"
            >
              Leave
            </button>
            <button
              onClick={handleLogout}
              title="Sign out"
              className="flex items-center gap-1.5 text-slate-400 active:text-red-500 transition-colors cursor-pointer p-1"
            >
              <LogOutIcon />
            </button>
          </div>
        </div>

        <div className="mt-3">
          <div className="text-[18px] font-semibold text-slate-800 leading-tight">My Deliveries</div>
          <div className="text-[12px] text-slate-400 mt-0.5">
            {new Date().toLocaleDateString("en-PH", { weekday: "long", year: "numeric", month: "long", day: "numeric" })}
          </div>
        </div>

        {/* Summary pills */}
        <div className="flex gap-2 mt-3">
          {summary.map((s) => (
            <div key={s.label} className="flex-1 bg-slate-50 border border-black/[0.08] rounded-xl px-2 py-2 text-center">
              <div className={`text-[16px] font-semibold font-mono leading-none ${s.color}`}>{s.value}</div>
              <div className="text-[9px] text-slate-400 mt-0.5 tracking-wide">{s.label}</div>
            </div>
          ))}
        </div>
      </header>

      {/* ── Filter Tabs ── */}
      <div className="bg-white border-b border-black/[0.08] px-5 flex gap-1.5 overflow-x-auto flex-shrink-0">
        {FILTERS.map((f) => (
          <button
            key={f}
            onClick={() => setFilter(f)}
            className={`flex-shrink-0 px-3.5 py-3 text-[12px] font-medium border-b-2 transition-colors cursor-pointer ${
              filter === f ? "text-cyan-500 border-cyan-500" : "text-slate-400 border-transparent"
            }`}
          >
            {f}
          </button>
        ))}
      </div>

      {/* ── Delivery List ── */}
      <div className="flex-1 px-4 py-4 flex flex-col gap-3 overflow-y-auto pb-8">
        {loading && (
          <div className="text-center text-[13px] text-slate-400 mt-12">Loading deliveries…</div>
        )}

        {!loading && loadError && (
          <div className="text-center text-[13px] text-red-500 bg-red-50 border border-red-100 rounded-xl px-4 py-3 mt-4">
            {loadError}
          </div>
        )}

        {!loading && !loadError && visible.length === 0 && (
          <div className="text-center text-[13px] text-slate-400 mt-12">No deliveries found.</div>
        )}

        {!loading &&
          visible.map((d) => {
            const cfg = STATUS_CONFIG[d.status] || STATUS_CONFIG.pending;
            const open = expanded === d.id;
            const storeName = d.store_name || d.customer?.name || "Store";
            const address = d.address || d.customer?.address || "—";
            // Real delivery number (DEL-0007). Previously this was built
            // from the database id, which showed DEL-0008 for id 8.
            const orderCode = d.delivery_no || `#${d.id}`;

            return (
              <div key={d.id} className="bg-white border border-black/[0.08] rounded-2xl overflow-hidden shadow-sm">

                {/* Card header */}
                <button
                  className="w-full text-left px-4 py-4 flex items-start gap-3 cursor-pointer active:bg-slate-50 transition-colors"
                  onClick={() => setExpanded(open ? null : d.id)}
                >
                  <div className="mt-1 flex-shrink-0">
                    <span className={`w-2 h-2 rounded-full inline-block ${cfg.dot}`} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-[13px] font-semibold text-slate-800 truncate">{storeName}</span>
                      <span className={`flex-shrink-0 text-[10px] font-medium px-2 py-0.5 rounded-full ${cfg.bg} ${cfg.text}`}>
                        {cfg.label}
                      </span>
                    </div>
                    <div className="flex items-center gap-1 mt-1 text-[11px] text-slate-400">
                      <MapPinIcon />
                      <span className="truncate">{address}</span>
                    </div>
                    <div className="flex items-center gap-3 mt-1.5">
                      {d.created_at && (
                        <>
                          <span className="text-[10px] text-slate-400 flex items-center gap-1">
                            <ClockIcon />
                            {new Date(d.created_at).toLocaleTimeString("en-PH", { hour: "2-digit", minute: "2-digit" })}
                          </span>
                          <span className="text-[10px] text-slate-300">·</span>
                        </>
                      )}
                      <span className="text-[10px] font-mono text-slate-400">{orderCode}</span>
                    </div>
                  </div>
                  <span className={`text-slate-300 flex-shrink-0 mt-1 transition-transform duration-200 ${open ? "rotate-90" : ""}`}>
                    <ChevronRightIcon />
                  </span>
                </button>

                {/* Expanded detail */}
                {open && (
                  <div className="border-t border-black/[0.07] px-4 py-4 flex flex-col gap-3">
                    <div className="grid grid-cols-2 gap-3">
                      <div className="flex flex-col gap-0.5 col-span-2">
                        <span className="text-[10px] text-slate-400 uppercase tracking-widest">Store</span>
                        <div className="flex items-center gap-1.5 text-[12px] text-slate-700 font-medium">
                          <UserIcon />{storeName}
                        </div>
                      </div>
                      <div className="flex flex-col gap-0.5 col-span-2">
                        <span className="text-[10px] text-slate-400 uppercase tracking-widest">Address</span>
                        <div className="flex items-center gap-1.5 text-[12px] text-slate-700 font-medium">
                          <MapPinIcon />{address}
                        </div>
                      </div>
                      {Array.isArray(d.delivery_items) && d.delivery_items.length > 0 && (
                        <div className="flex flex-col gap-0.5 col-span-2">
                          <span className="text-[10px] text-slate-400 uppercase tracking-widest">Items</span>
                          <div className="flex items-center gap-1.5 text-[12px] text-slate-700 font-medium">
                            <PackageIcon />
                            {d.delivery_items
                              .map((item) => `${item.product_name || item.name || "Item"}${item.quantity ? ` (${item.quantity})` : ""}`)
                              .join(", ")}
                          </div>
                        </div>
                      )}
                    </div>
                      
                    {d.status !== "delivered" && d.status !== "cancelled" && (
                      <button
                        onClick={() => navigate(`/salesman/deliveries/${d.id}/confirm`)}
                        className="w-full py-3 bg-cyan-500 active:bg-cyan-600 text-white text-[12px] font-semibold rounded-xl transition-colors cursor-pointer flex items-center justify-center gap-1.5"
                      >
                        <CameraIcon />
                        Confirm Delivery
                      </button>
                    )}

                    {d.status === "delivered" && (
                      <div className="flex items-center gap-2 bg-green-50 border border-green-100 rounded-xl px-3 py-2.5">
                        <span className="w-2 h-2 rounded-full bg-green-500 flex-shrink-0" />
                        <span className="text-[12px] text-green-600 font-medium">Delivery confirmed and recorded.</span>
                      </div>
                    )}
                  </div>
                )}
              </div>
            );
          })}
      </div>
    </div>
  );
}