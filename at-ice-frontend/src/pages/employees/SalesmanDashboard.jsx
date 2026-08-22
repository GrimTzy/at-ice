import { useState, useEffect, useRef } from "react";
import { useNavigate } from "react-router-dom";
import logo from "../../assets/logo.png";

// Icons 
const BoltIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
  </svg>
);
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
const XIcon = () => (
  <svg width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <line x1="18" y1="6" x2="6" y2="18" />
    <line x1="6" y1="6" x2="18" y2="18" />
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

  // Confirmation sheet state
  const [activeDelivery, setActiveDelivery] = useState(null);
  const [quantity, setQuantity] = useState("");
  const [unitPrice, setUnitPrice] = useState("");
  const [photoFile, setPhotoFile] = useState(null);
  const [photoPreview, setPhotoPreview] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState("");
  const [successReceipt, setSuccessReceipt] = useState(null);
  const fileInputRef = useRef(null);

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

  const openConfirmSheet = (d) => {
    setActiveDelivery(d);
    setQuantity("");
    setUnitPrice(d.unit_price ? String(d.unit_price) : "");
    setPhotoFile(null);
    setPhotoPreview(null);
    setSubmitError("");
    setSuccessReceipt(null);
  };

  const closeConfirmSheet = () => setActiveDelivery(null);

  const handlePhotoChange = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setPhotoFile(file);
    setPhotoPreview(URL.createObjectURL(file));
  };

  const handleConfirmSubmit = async () => {
    setSubmitError("");
    if (!quantity || Number(quantity) <= 0) {
      setSubmitError("Enter the exact quantity of sacks delivered.");
      return;
    }
    if (!unitPrice || Number(unitPrice) <= 0) {
      setSubmitError("Enter the unit price.");
      return;
    }
    if (!photoFile) {
      setSubmitError("Upload a photo proof of the delivery.");
      return;
    }

    setSubmitting(true);
    try {
      const formData = new FormData();
      formData.append("delivery_id", activeDelivery.id);
      formData.append("quantity", quantity);
      formData.append("unit_price", unitPrice);
      formData.append("photo", photoFile);

      const res = await fetch("/api/delivery-confirmation", {
        method: "POST",
        headers: { Accept: "application/json", Authorization: `Bearer ${token()}` },
        body: formData,
      });

      if (!res.ok) {
        const errData = await res.json().catch(() => ({}));
        throw new Error(errData.message || "Delivery confirmation failed.");
      }

      const result = await res.json();
      setSuccessReceipt(result.receipt || result);
      fetchDeliveries();
    } catch (err) {
      setSubmitError(err.message || "Something went wrong. Try again.");
    } finally {
      setSubmitting(false);
    }
  };

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
          <button
            onClick={() => navigate("/salesman/login")}
            className="flex items-center gap-1.5 text-slate-400 active:text-red-500 transition-colors cursor-pointer p-1"
          >
            <LogOutIcon />
          </button>
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
            const orderCode = `DEL-${String(d.id).padStart(4, "0")}`;

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
                        onClick={() => openConfirmSheet(d)}
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

      {/* ── Delivery Confirmation Bottom Sheet ── */}
      {activeDelivery && (
        <div className="fixed inset-0 z-50 flex items-end">
          <div
            className="absolute inset-0 bg-black/40"
            onClick={submitting ? undefined : closeConfirmSheet}
          />
          <div className="relative w-full bg-white rounded-t-3xl px-5 pt-4 pb-8 max-h-[88vh] overflow-y-auto">
            <div className="w-10 h-1 bg-slate-200 rounded-full mx-auto mb-4" />

            <div className="flex items-center justify-between mb-4">
              <span className="text-[14px] font-semibold text-slate-800">
                {successReceipt ? "Confirmed" : "Confirm Delivery"}
              </span>
              <button onClick={closeConfirmSheet} className="text-slate-400 cursor-pointer">
                <XIcon />
              </button>
            </div>

            {successReceipt ? (
              <div className="flex flex-col gap-4">
                <div className="flex items-center gap-2 bg-green-50 border border-green-100 rounded-xl px-4 py-3">
                  <span className="w-2 h-2 rounded-full bg-green-500 flex-shrink-0" />
                  <span className="text-[12px] text-green-600 font-medium">
                    Delivery confirmed. Sale recorded automatically.
                  </span>
                </div>

                {/* Digital receipt copy */}
                <div className="border border-black/[0.08] rounded-2xl overflow-hidden">
                  <div className="bg-slate-50 border-b border-black/[0.08] px-4 py-3 flex items-center justify-between">
                    <span className="text-[11px] text-slate-400 uppercase tracking-widest">Receipt</span>
                    <span className="text-[12px] font-mono font-semibold text-slate-800">
                      {successReceipt.receipt_no || "—"}
                    </span>
                  </div>
                  <div className="px-4 py-3.5 flex flex-col gap-2.5">
                    <div className="flex items-center justify-between text-[12px]">
                      <span className="text-slate-400">Store</span>
                      <span className="text-slate-700 font-medium">
                        {activeDelivery?.customer?.name || "—"}
                      </span>
                    </div>
                    <div className="flex items-center justify-between text-[12px]">
                      <span className="text-slate-400">Quantity</span>
                      <span className="text-slate-700 font-mono">
                        {successReceipt.quantity ?? "—"} sacks
                      </span>
                    </div>
                    <div className="flex items-center justify-between text-[12px]">
                      <span className="text-slate-400">Unit Price</span>
                      <span className="text-slate-700 font-mono">
                        ₱{Number(successReceipt.unit_price ?? 0).toFixed(2)}
                      </span>
                    </div>
                    <div className="flex items-center justify-between text-[13px] pt-2 border-t border-black/[0.06]">
                      <span className="text-slate-500 font-medium">Total Amount</span>
                      <span className="text-slate-800 font-mono font-semibold">
                        ₱{Number(successReceipt.total_amount ?? 0).toFixed(2)}
                      </span>
                    </div>
                    <div className="flex items-center justify-between text-[11px] mt-1">
                      <span className="text-slate-400">Payment Status</span>
                      <span className="px-2 py-0.5 rounded-full bg-amber-50 text-amber-600 font-medium capitalize">
                        {successReceipt.payment_status || "unpaid"}
                      </span>
                    </div>
                  </div>
                </div>

                <p className="text-[11px] text-slate-400 text-center leading-relaxed">
                  This copy is saved to your account and visible on the Admin dashboard.
                </p>

                <button
                  onClick={closeConfirmSheet}
                  className="w-full py-3.5 bg-cyan-500 active:bg-cyan-600 text-white text-[13px] font-semibold rounded-xl transition-colors cursor-pointer"
                >
                  Done
                </button>
              </div>
            ) : (
              <div className="flex flex-col gap-4">
                <div className="text-[12px] text-slate-400">
                  {activeDelivery.store_name || activeDelivery.customer?.name || "Store"} · DEL-
                  {String(activeDelivery.id).padStart(4, "0")}
                </div>

                <div className="flex flex-col gap-2">
                  <label className="text-[11px] font-medium text-slate-500 tracking-widest uppercase px-0.5">
                    Quantity Delivered (sacks)
                  </label>
                  <input
                    type="number"
                    min="1"
                    value={quantity}
                    onChange={(e) => setQuantity(e.target.value)}
                    placeholder="e.g. 50"
                    className="px-4 py-3.5 border border-black/[0.12] rounded-xl bg-slate-50/80 text-[15px] font-mono text-slate-800 outline-none focus:border-cyan-400 focus:bg-white focus:ring-2 focus:ring-cyan-500/10 transition-all"
                  />
                </div>

                <div className="flex flex-col gap-2">
                  <label className="text-[11px] font-medium text-slate-500 tracking-widest uppercase px-0.5">
                    Unit Price (₱)
                  </label>
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    value={unitPrice}
                    onChange={(e) => setUnitPrice(e.target.value)}
                    placeholder="e.g. 45.00"
                    className="px-4 py-3.5 border border-black/[0.12] rounded-xl bg-slate-50/80 text-[15px] font-mono text-slate-800 outline-none focus:border-cyan-400 focus:bg-white focus:ring-2 focus:ring-cyan-500/10 transition-all"
                  />
                </div>

                <div className="flex flex-col gap-2">
                  <label className="text-[11px] font-medium text-slate-500 tracking-widest uppercase px-0.5">
                    Photo Proof
                  </label>
                  <input
                    ref={fileInputRef}
                    type="file"
                    accept="image/*"
                    capture="environment"
                    onChange={handlePhotoChange}
                    className="hidden"
                  />
                  {photoPreview ? (
                    <div className="relative">
                      <img
                        src={photoPreview}
                        alt="Proof preview"
                        className="w-full h-44 object-cover rounded-xl border border-black/[0.08]"
                      />
                      <button
                        onClick={() => fileInputRef.current?.click()}
                        className="absolute bottom-2 right-2 text-[11px] font-medium bg-white/90 px-2.5 py-1.5 rounded-lg border border-black/[0.08] cursor-pointer"
                      >
                        Retake
                      </button>
                    </div>
                  ) : (
                    <button
                      onClick={() => fileInputRef.current?.click()}
                      className="h-32 rounded-xl border-2 border-dashed border-black/[0.12] flex flex-col items-center justify-center gap-1.5 text-slate-400 cursor-pointer"
                    >
                      <CameraIcon />
                      <span className="text-[12px]">Tap to take / upload photo</span>
                    </button>
                  )}
                </div>

                {submitError && (
                  <div className="text-[12px] text-red-500 bg-red-50 border border-red-100 rounded-xl px-4 py-3">
                    {submitError}
                  </div>
                )}

                <button
                  onClick={handleConfirmSubmit}
                  disabled={submitting}
                  className="w-full py-3.5 bg-cyan-500 active:bg-cyan-600 text-white text-[13px] font-semibold rounded-xl transition-colors cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center gap-2.5 shadow-lg shadow-cyan-500/25"
                >
                  {submitting ? (
                    <>
                      <span className="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                      Submitting…
                    </>
                  ) : (
                    "Submit Confirmation"
                  )}
                </button>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}