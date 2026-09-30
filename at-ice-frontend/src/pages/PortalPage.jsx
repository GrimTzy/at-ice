import { useNavigate } from "react-router-dom";
import logo from "../assets/logo.png";
import bgImage from "../assets/iceproduct.png";

const ArrowIcon = () => (
  <svg width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" />
  </svg>
);

const GROUPS = [
  {
    label: "Office",
    items: [
      { title: "Admin", desc: "Business intelligence, deliveries, customers, receipts", path: "/admin/login" },
      { title: "Human Resources", desc: "Employees, attendance, leave, payroll, interviews", path: "/hr/login" },
    ],
  },
  {
    label: "Plant and Field",
    items: [
      { title: "Checker", desc: "Inventory, stock in and out, suppliers, pickups", path: "/checker/login" },
      { title: "Salesman", desc: "Delivery confirmation with photo proof", path: "/salesman/login" },
      { title: "Maintenance", desc: "Stock-out requests for truck and machine parts", path: "/maintenance/login" },
      { title: "Driver", desc: "Assigned deliveries and GPS tracking", path: "/driver/login" },
    ],
  },
  {
    label: "Customers and Kiosk",
    items: [
      { title: "Customer Portal", desc: "Deliveries, delivery proofs, receipts and balance", path: "/customer/login" },
      { title: "Attendance Station", desc: "Face recognition time-in kiosk", path: "/attendance-station" },
    ],
  },
];

export default function PortalPage() {
  const navigate = useNavigate();

  return (
    <div className="relative min-h-screen w-full overflow-x-hidden font-sans text-slate-100">
      {/* Background */}
      <div
        className="fixed inset-0"
        style={{ backgroundImage: `url(${bgImage})`, backgroundSize: "cover", backgroundPosition: "center" }}
      />
      <div className="fixed inset-0 bg-gradient-to-b from-[#0b1e3c]/90 via-[#0b1e3c]/92 to-[#070f1f]/95" />

      <div className="relative z-10 max-w-5xl mx-auto px-5 py-10 sm:py-14 flex flex-col gap-10">

        {/* Brand */}
        <header className="flex flex-col items-center text-center gap-3">
          <div className="w-16 h-16 bg-white/95 border border-cyan-400/30 rounded-2xl flex items-center justify-center overflow-hidden shadow-lg shadow-cyan-500/20">
            <img src={logo} alt="Bella Erin logo" className="w-full h-full object-contain p-1.5" />
          </div>
          <div>
            <h1 className="text-[28px] sm:text-[34px] font-semibold tracking-wide leading-tight">Bella Erin Tube Ice Inc.</h1>
            <div className="text-[11px] text-cyan-300 tracking-[0.2em] uppercase mt-1">AT-ICE ERP</div>
          </div>
          <p className="text-[13px] text-slate-300 max-w-xl leading-relaxed">
            Enterprise resource planning for ice manufacturing: HR, inventory, deliveries with GPS tracking, customer relations and business intelligence.
          </p>
          <div className="text-[12px] text-slate-400">Select your module to sign in.</div>
        </header>

        {/* Module cards */}
        <main className="flex flex-col gap-7">
          {GROUPS.map((group) => (
            <section key={group.label} className="flex flex-col gap-3">
              <div className="text-[10px] tracking-[0.18em] uppercase text-cyan-300/80 font-medium px-1">{group.label}</div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {group.items.map((item) => (
                  <button
                    key={item.title}
                    type="button"
                    onClick={() => navigate(item.path)}
                    className="group text-left bg-white/[0.06] hover:bg-white/[0.11] border border-white/10 hover:border-cyan-400/50 rounded-2xl px-5 py-4 flex items-center justify-between gap-4 transition-colors cursor-pointer backdrop-blur-sm"
                  >
                    <div className="min-w-0">
                      <div className="text-[15px] font-semibold text-white">{item.title}</div>
                      <div className="text-[12px] text-slate-300 mt-0.5 leading-snug">{item.desc}</div>
                    </div>
                    <span className="text-slate-400 group-hover:text-cyan-300 transition-colors flex-shrink-0">
                      <ArrowIcon />
                    </span>
                  </button>
                ))}
              </div>
            </section>
          ))}
        </main>

        {/* Footer */}
        <footer className="text-center flex flex-col gap-1 pt-2 border-t border-white/10">
          <div className="text-[11px] text-slate-400 pt-4">
            Block 63, Lot 3 Anubing St, Amparo Subdivision, Barangay 179 Zone 16, Caloocan City North, 1425, Metro Manila
          </div>
          <div className="text-[10px] text-slate-500 tracking-wide">© 2026 AT-ICE ERP · Bella Erin Tube Ice Inc.</div>
        </footer>
      </div>
    </div>
  );
}