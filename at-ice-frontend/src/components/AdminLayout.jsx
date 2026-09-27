import { useNavigate, useLocation } from "react-router-dom";
import logo from "../assets/logo.png";
import bgImage from "../assets/iceproduct.png";

const GridIcon     = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>;
const UsersIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>;
const DollarIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>;
const TruckIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>;
const CheckIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>;
const UserIcon     = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>;
const ClipboardIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12l2 2 4-4"/></svg>;
const ReceiptIcon  = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M4 2h16v20l-3-2-3 2-3-2-3 2-3-2-1 2V2z"/><line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="11" x2="16" y2="11"/><line x1="8" y1="15" x2="12" y2="15"/></svg>;
const ChevDown     = () => <svg width="10" height="10" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>;
const ChevRight    = () => <svg width="10" height="10" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>;
const CalIcon      = () => <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>;
const LogOutIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>;

const navSections = [
  { label:"Overview", items:[{ icon:<GridIcon />, label:"Dashboard", path:"/dashboard" }] },
  { label:"HRIS",     items:[{ icon:<UsersIcon />, label:"Employees", path:"/admin/employees" }] },
  { label:"SCM",      items:[
      { icon:<TruckIcon />, label:"Deliveries", path:"/admin/deliveries" },
      { icon:<CheckIcon />, label:"Delivery Proofs" },
      { icon:<ClipboardIcon />, label:"Stock-Out Approvals", path:"/admin/stock-out-approvals" },
    ] },
  { label:"CRM",      items:[
      { icon:<UserIcon />, label:"Customers", path:"/admin/customers" },
      { icon:<ReceiptIcon />, label:"Receipts", path:"/admin/receipts" },
      { icon:<DollarIcon />, label:"Customer Prices", path:"/admin/customer-prices" },
    ] },
];

export default function AdminLayout({ pageTitle, breadcrumb, headerActions, children }) {
  const navigate = useNavigate();
  const location = useLocation();
  const user = JSON.parse(localStorage.getItem("user") || "{}");

  const today = new Date().toLocaleDateString("en-PH", { month:"long", day:"numeric", year:"numeric" });

  const handleLogout = () => {
    ["token", "role", "sub_role", "user"].forEach((k) => localStorage.removeItem(k));
    navigate("/admin/login");
  };

  return (
    <div className="flex font-sans text-[13px] leading-relaxed bg-[#0a0d12] text-slate-800 min-h-screen">

      <aside className="w-[220px] min-h-screen bg-white border-r border-black/[0.09] flex flex-col flex-shrink-0 sticky top-0 h-screen overflow-y-auto">
        <div
          className="px-[18px] pt-5 pb-4 border-b border-black/[0.09] flex items-center gap-2.5 cursor-pointer"
          onClick={() => navigate("/dashboard")}
        >
          <div className="w-[34px] h-[34px] bg-slate-900 border border-cyan-500/20 rounded-md flex items-center justify-center overflow-hidden">
            <img src={logo} alt="Bella Erin logo" className="w-full h-full object-contain p-1" />
          </div>
          <div>
            <div className="text-[15px] font-semibold text-slate-800 tracking-wide">Bella Erin</div>
            <div className="text-[10px] text-slate-400 tracking-widest uppercase">AT-ICE ERP</div>
          </div>
        </div>
        <nav className="py-2.5 flex-1">
          {navSections.map((sec) => (
            <div key={sec.label}>
              <div className="px-[18px] pt-3.5 pb-1 text-[10px] tracking-[0.1em] uppercase text-slate-400 font-medium">{sec.label}</div>
              {sec.items.map((item) => {
                const isActive = item.path && location.pathname === item.path;
                return (
                  <div
                    key={item.label}
                    onClick={() => item.path && navigate(item.path)}
                    className={`flex items-center gap-2.5 px-[18px] py-[7px] text-[13px] cursor-pointer transition-all duration-100 border-l-2 ${isActive ? "text-cyan-500 bg-cyan-500/[0.08] border-cyan-500" : "text-slate-500 border-transparent hover:text-slate-800 hover:bg-slate-50"}`}
                  >
                    <span className="opacity-80">{item.icon}</span>{item.label}
                  </div>
                );
              })}
            </div>
          ))}
        </nav>
        <div className="px-[18px] py-3.5 border-t border-black/[0.09] flex items-center gap-2.5">
          <div className="w-[30px] h-[30px] rounded-full bg-cyan-500/10 border border-cyan-400/25 flex items-center justify-center text-[11px] font-semibold text-cyan-500 flex-shrink-0">AD</div>
          <div className="min-w-0">
            <div className="text-xs font-medium text-slate-800 truncate">{user?.name || "Admin"}</div>
            <div className="text-[10px] text-slate-400">System Administrator</div>
          </div>
          <button
            onClick={handleLogout}
            title="Sign out"
            className="ml-auto text-slate-400 hover:text-red-500 transition-colors cursor-pointer p-1"
          >
            <LogOutIcon />
          </button>
        </div>
      </aside>

      <div className="flex-1 min-w-0 flex flex-col overflow-hidden">

        <header className="h-14 bg-white border-b border-black/[0.09] flex items-center px-6 gap-4 flex-shrink-0 sticky top-0 z-10">
          <div>
            <div className="text-base font-semibold text-slate-800">{pageTitle}</div>
            <div className="text-xs text-slate-400 flex items-center gap-1">
              Home <ChevRight /> <span className="text-slate-600">{breadcrumb || pageTitle}</span>
            </div>
          </div>
          <div className="flex-1" />
          {headerActions}
          <div className="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-black/[0.09] rounded-md text-xs text-slate-500">
            <CalIcon /> {today}
          </div>
          <div
            onClick={handleLogout}
            title="Sign out"
            className="flex items-center gap-2.5 px-2.5 py-1.5 pl-1.5 bg-white border border-black/[0.09] rounded-md cursor-pointer hover:bg-slate-50 transition-colors"
          >
            <div className="w-[30px] h-[30px] rounded-full bg-gradient-to-br from-cyan-500/10 to-blue-600/10 border border-cyan-500/20 flex items-center justify-center text-[11px] font-semibold text-cyan-500">AD</div>
            <div className="min-w-0">
              <div className="text-xs font-medium text-slate-800 truncate">{user?.name || "Admin"}</div>
              <div className="text-[10px] text-slate-400">System Administrator</div>
            </div>
            <span className="ml-1 text-slate-400"><ChevDown /></span>
          </div>
        </header>

        <main
          className="flex-1 overflow-y-auto p-6 pb-8 flex flex-col gap-4"
          style={{
            backgroundImage: `linear-gradient(rgba(248,250,252,0.88), rgba(248,250,252,0.88)), url(${bgImage})`,
            backgroundSize: "cover",
            backgroundPosition: "center",
            backgroundAttachment: "fixed",
          }}
        >
          {children}
        </main>
      </div>
    </div>
  );
}