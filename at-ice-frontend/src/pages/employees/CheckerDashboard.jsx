import { useNavigate } from "react-router-dom";
import logo from "../../assets/logo.png";

// Icons
const BoxIcon = () => (
  <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8" />
  </svg>
);
const SwapIcon = () => (
  <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M7 16V4M7 4L3 8M7 4l4 4M17 8v12M17 20l4-4M17 20l-4-4" />
  </svg>
);
const AlertIcon = () => (
  <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
  </svg>
);
const TruckIcon = () => (
  <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M1 3h15v13H1zM16 8h4l3 3v5h-7V8zM5.5 21a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM18.5 21a2.5 2.5 0 100-5 2.5 2.5 0 000 5z" />
  </svg>
);
const ClipboardIcon = () => (
  <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
  </svg>
);
const SnowflakeIcon = () => (
  <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M12 2v20M4.93 4.93l14.14 14.14M19.07 4.93L4.93 19.07M12 8l-3-3M12 8l3-3M12 16l-3 3M12 16l3 3" />
  </svg>
);
const WrenchIcon = () => (
  <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M14.7 6.3a4 4 0 10-5.66 5.66L2 19l3 3 7.03-7.04a4 4 0 005.67-5.66l-2.83 2.83-2.12-2.12 2.83-2.83z" />
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

const MODULES = [
  {
    title: "Pickup Log",
    description: "Log Alexandra and J-Maxine pickups at the plant.",
    icon: ClipboardIcon,
    dot: "bg-cyan-500",
    route: "/checker/pickup-log",
  },
  {
    title: "Ice Loading Count",
    description: "Count sacks loaded per delivery truck.",
    icon: SnowflakeIcon,
    dot: "bg-blue-500",
    route: "/checker/ice-loading",
  },
  {
    title: "Inventory Management",
    description: "View and manage supplies and materials stock.",
    icon: BoxIcon,
    dot: "bg-slate-400",
    route: "/checker/inventory",
  },
  {
    title: "Stock In / Stock Out",
    description: "Log stock movement with double-entry confirmation.",
    icon: SwapIcon,
    dot: "bg-green-500",
    route: "/checker/stock-movements",
  },
  {
    title: "Low Stock Alert",
    description: "Review items below their minimum stock level.",
    icon: AlertIcon,
    dot: "bg-amber-400",
    route: "/checker/low-stock",
  },
  {
    title: "Supplier Management",
    description: "Manage supplier records and details.",
    icon: TruckIcon,
    dot: "bg-slate-400",
    route: "/checker/suppliers",
  },
  {
    title: "Maintenance Stock-Out Release",
    description: "Release Admin-approved Maintenance requests.",
    icon: WrenchIcon,
    dot: "bg-cyan-500",
    route: "/checker/stock-out-release",
  },
];

export default function CheckerDashboard() {
  const navigate = useNavigate();
  const user = JSON.parse(localStorage.getItem("user") || "{}");

  const handleLogout = () => {
    localStorage.removeItem("token");
    localStorage.removeItem("role");
    localStorage.removeItem("sub_role");
    localStorage.removeItem("user");
    navigate("/checker/login");
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
                {user?.name || "Checker"}
              </div>
              <div className="text-[10px] text-slate-400 tracking-wide">Checker</div>
            </div>
          </div>
          <button
            onClick={handleLogout}
            className="flex items-center gap-1.5 text-slate-400 active:text-red-500 transition-colors cursor-pointer p-1"
          >
            <LogOutIcon />
          </button>
        </div>

        <div className="mt-3">
          <div className="text-[18px] font-semibold text-slate-800 leading-tight">Checker Dashboard</div>
          <div className="text-[12px] text-slate-400 mt-0.5">
            {new Date().toLocaleDateString("en-PH", { weekday: "long", year: "numeric", month: "long", day: "numeric" })}
          </div>
        </div>
      </header>

      {/* ── Module List ── */}
      <div className="flex-1 px-4 py-4 flex flex-col gap-3 overflow-y-auto pb-8">
        {MODULES.map((mod) => (
          <button
            key={mod.title}
            onClick={() => navigate(mod.route)}
            className="w-full text-left bg-white border border-black/[0.08] rounded-2xl overflow-hidden shadow-sm px-4 py-4 flex items-start gap-3 cursor-pointer active:bg-slate-50 transition-colors"
          >
            <div className="mt-1 flex-shrink-0">
              <span className={`w-2 h-2 rounded-full inline-block ${mod.dot}`} />
            </div>
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-1.5 text-[13px] font-semibold text-slate-800">
                <mod.icon />
                {mod.title}
              </div>
              <div className="text-[11px] text-slate-400 mt-1 leading-snug">
                {mod.description}
              </div>
            </div>
            <span className="text-slate-300 flex-shrink-0 mt-1">
              <ChevronRightIcon />
            </span>
          </button>
        ))}
      </div>
    </div>
  );
}