import { useState } from "react";
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
const PhoneIcon = () => (
  <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.37 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.37a16 16 0 0 0 6.72 6.72l.97-.97a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z" />
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
const MapIcon = () => (
  <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6" />
    <line x1="8" y1="2" x2="8" y2="18" />
    <line x1="16" y1="6" x2="16" y2="22" />
  </svg>
);

// ─── Mock data ────────────────────────────────────────────────────────────────
const deliveries = [
  { id: "ORD-2026-418", customer: "SM Hypermarket – Caloocan",  phone: "0917-123-4567", destination: "Grace Park, Caloocan City",        items: "TI-10KG Tube Ice",   qty: 24, status: "In Transit", time: "8:00 AM",  distance: "3.2 km" },
  { id: "ORD-2026-415", customer: "Pickup Coffee Inc.",          phone: "0918-234-5678", destination: "Quezon Ave, Quezon City",          items: "TI-5KG Tube Ice",    qty: 40, status: "Pending",    time: "9:30 AM",  distance: "5.8 km" },
  { id: "ORD-2026-412", customer: "Alfamart – Branch 045",       phone: "0919-345-6789", destination: "EDSA, Balintawak, Quezon City",    items: "TI-FLAKES Ice Flakes", qty: 10, status: "Delivered", time: "7:00 AM",  distance: "2.1 km" },
  { id: "ORD-2026-409", customer: "WalterMart – North EDSA",     phone: "0920-456-7890", destination: "North EDSA, Quezon City",          items: "TI-20KG Tube Ice",   qty: 15, status: "Pending",    time: "10:00 AM", distance: "6.4 km" },
  { id: "ORD-2026-405", customer: "Savemore – Monumento",        phone: "0921-567-8901", destination: "Rizal Ave Ext., Caloocan City",    items: "TI-3KG Tube Ice",    qty: 60, status: "Delivered",  time: "6:30 AM",  distance: "1.4 km" },
  { id: "ORD-2026-401", customer: "7-Eleven – Samson Rd.",       phone: "0922-678-9012", destination: "Samson Rd., Caloocan City",        items: "TI-5KG Tube Ice",    qty: 20, status: "Cancelled",  time: "7:30 AM",  distance: "0.9 km" },
];

const STATUS_CONFIG = {
  "Pending":    { bg: "bg-amber-50",  text: "text-amber-600",  dot: "bg-amber-400",  label: "Pending"    },
  "In Transit": { bg: "bg-blue-50",   text: "text-blue-600",   dot: "bg-blue-500",   label: "In Transit" },
  "Delivered":  { bg: "bg-green-50",  text: "text-green-600",  dot: "bg-green-500",  label: "Delivered"  },
  "Cancelled":  { bg: "bg-slate-100", text: "text-slate-400",  dot: "bg-slate-300",  label: "Cancelled"  },
};

const FILTERS = ["All", "Pending", "In Transit", "Delivered"];

const SUMMARY = [
  { label: "Total",      value: 6, color: "text-slate-800" },
  { label: "Pending",    value: 2, color: "text-amber-500" },
  { label: "In Transit", value: 1, color: "text-blue-500"  },
  { label: "Delivered",  value: 2, color: "text-green-500" },
];

export function DeliveriesPage() {
  const navigate = useNavigate();
  const [filter, setFilter] = useState("All");
  const [expanded, setExpanded] = useState(null);

  const visible = filter === "All" ? deliveries : deliveries.filter((d) => d.status === filter);

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
              <div className="text-[13px] font-semibold text-slate-800 leading-none">Juan Dela Cruz</div>
              <div className="text-[10px] text-slate-400 tracking-wide">Driver · Route A</div>
            </div>
          </div>
          <div className="flex items-center gap-3">
            {/* GPS Tracking button */}
            <button
              onClick={() => navigate("/driver/gps")}
              className="flex items-center gap-1.5 px-3 py-1.5 bg-cyan-500/10 border border-cyan-500/20 rounded-lg text-cyan-600 text-[11px] font-medium active:bg-cyan-500/20 transition-colors cursor-pointer"
            >
              <MapIcon />
              GPS
            </button>
            <button
              onClick={() => navigate("/driver/login")}
              className="flex items-center gap-1.5 text-slate-400 active:text-red-500 transition-colors cursor-pointer p-1"
            >
              <LogOutIcon />
            </button>
          </div>
        </div>

        <div className="mt-3">
          <div className="text-[18px] font-semibold text-slate-800 leading-tight">My Deliveries</div>
          <div className="text-[12px] text-slate-400 mt-0.5">Monday, June 8, 2026</div>
        </div>

        {/* Summary pills */}
        <div className="flex gap-2 mt-3">
          {SUMMARY.map((s) => (
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
        {visible.length === 0 && (
          <div className="text-center text-[13px] text-slate-400 mt-12">No deliveries found.</div>
        )}
        {visible.map((d) => {
          const cfg = STATUS_CONFIG[d.status];
          const open = expanded === d.id;
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
                    <span className="text-[13px] font-semibold text-slate-800 truncate">{d.customer}</span>
                    <span className={`flex-shrink-0 text-[10px] font-medium px-2 py-0.5 rounded-full ${cfg.bg} ${cfg.text}`}>
                      {cfg.label}
                    </span>
                  </div>
                  <div className="flex items-center gap-1 mt-1 text-[11px] text-slate-400">
                    <MapPinIcon />
                    <span className="truncate">{d.destination}</span>
                  </div>
                  <div className="flex items-center gap-3 mt-1.5">
                    <span className="text-[10px] text-slate-400 flex items-center gap-1"><ClockIcon />{d.time}</span>
                    <span className="text-[10px] text-slate-300">·</span>
                    <span className="text-[10px] text-slate-400">{d.distance}</span>
                    <span className="text-[10px] text-slate-300">·</span>
                    <span className="text-[10px] font-mono text-slate-400">{d.id}</span>
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
                    <div className="flex flex-col gap-0.5">
                      <span className="text-[10px] text-slate-400 uppercase tracking-widest">Customer</span>
                      <div className="flex items-center gap-1.5 text-[12px] text-slate-700 font-medium">
                        <UserIcon />{d.customer}
                      </div>
                    </div>
                    <div className="flex flex-col gap-0.5">
                      <span className="text-[10px] text-slate-400 uppercase tracking-widest">Contact</span>
                      <div className="flex items-center gap-1.5 text-[12px] text-cyan-500 font-medium">
                        <PhoneIcon />{d.phone}
                      </div>
                    </div>
                    <div className="flex flex-col gap-0.5 col-span-2">
                      <span className="text-[10px] text-slate-400 uppercase tracking-widest">Destination</span>
                      <div className="flex items-center gap-1.5 text-[12px] text-slate-700 font-medium">
                        <MapPinIcon />{d.destination}
                      </div>
                    </div>
                    <div className="flex flex-col gap-0.5">
                      <span className="text-[10px] text-slate-400 uppercase tracking-widest">Items</span>
                      <div className="flex items-center gap-1.5 text-[12px] text-slate-700 font-medium">
                        <PackageIcon />{d.items}
                      </div>
                    </div>
                    <div className="flex flex-col gap-0.5">
                      <span className="text-[10px] text-slate-400 uppercase tracking-widest">Quantity</span>
                      <div className="text-[12px] text-slate-700 font-semibold font-mono">{d.qty} bags</div>
                    </div>
                  </div>

                  {/* Action buttons */}
                  {d.status !== "Delivered" && d.status !== "Cancelled" && (
                    <div className="flex gap-2 mt-1">
                      {d.status === "Pending" && (
                        <button className="flex-1 py-3 bg-blue-500 active:bg-blue-600 text-white text-[12px] font-semibold rounded-xl transition-colors cursor-pointer">
                          Start Delivery
                        </button>
                      )}
                      {d.status === "In Transit" && (
                        <button className="flex-1 py-3 bg-green-500 active:bg-green-600 text-white text-[12px] font-semibold rounded-xl transition-colors cursor-pointer">
                          Mark as Delivered
                        </button>
                      )}
                      <button
                        onClick={() => navigate("/driver/gps")}
                        className="flex-1 py-3 bg-slate-100 active:bg-slate-200 text-slate-600 text-[12px] font-semibold rounded-xl transition-colors cursor-pointer"
                      >
                        View on Map
                      </button>
                    </div>
                  )}

                  {d.status === "Delivered" && (
                    <div className="flex items-center gap-2 bg-green-50 border border-green-100 rounded-xl px-3 py-2.5">
                      <span className="w-2 h-2 rounded-full bg-green-500 flex-shrink-0" />
                      <span className="text-[12px] text-green-600 font-medium">Delivery completed successfully.</span>
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