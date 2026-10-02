import { useNavigate } from "react-router-dom";
import logo from "../../assets/logo.png";

// Functional UI icons only: navigation + actions, not decoration
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

// Stock-out release is not listed here: the Admin approves and releases
// Maintenance stock-out requests.
const MODULES = [
  {
    title: "Pickup Log",
    description: "Log Alexandra and J-Maxine pickups at the plant.",
    route: "/checker/pickup-log",
  },
  {
    title: "Ice Loading Count",
    description: "Count sacks loaded per delivery truck.",
    route: "/checker/ice-loading",
  },
  {
    title: "Inventory Management",
    description: "View stock levels and low stock alerts.",
    route: "/checker/inventory",
  },
  {
    title: "Stock In / Stock Out",
    description: "Log stock movement with double-entry confirmation.",
    route: "/checker/stock-movements",
  },
  {
    title: "Supplier Management",
    description: "Manage supplier records and details.",
    route: "/checker/suppliers",
  },
  {
    title: "Leave Request",
    description: "File a leave and view your past requests.",
    route: "/my-leave",
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

      {/* Top Bar */}
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

      {/* Module List: plain text, no decorative icons or color dots */}
      <div className="flex-1 px-4 py-4 flex flex-col gap-3 overflow-y-auto pb-8">
        {MODULES.map((mod) => (
          <button
            key={mod.title}
            onClick={() => navigate(mod.route)}
            className="w-full text-left bg-white border border-black/[0.08] rounded-2xl overflow-hidden shadow-sm px-4 py-4 flex items-center justify-between gap-3 cursor-pointer active:bg-slate-50 transition-colors"
          >
            <div className="flex-1 min-w-0">
              <div className="text-[13px] font-semibold text-slate-800">
                {mod.title}
              </div>
              <div className="text-[11px] text-slate-400 mt-1 leading-snug">
                {mod.description}
              </div>
            </div>
            <span className="text-slate-300 flex-shrink-0">
              <ChevronRightIcon />
            </span>
          </button>
        ))}
      </div>
    </div>
  );
}