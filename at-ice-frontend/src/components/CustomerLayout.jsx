import { useEffect, useState } from "react";
import { useNavigate, useLocation } from "react-router-dom";
import logo from "../assets/logo.png";
import bgImage from "../assets/iceproduct.png";

const GridIcon     = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>;
const TruckIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>;
const CameraIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>;
const ReceiptIcon  = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M4 2h16v20l-3-2-3 2-3-2-3 2-3-2-1 2V2z"/><line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="11" x2="16" y2="11"/><line x1="8" y1="15" x2="12" y2="15"/></svg>;
const TagIcon      = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M20.59 13.41 11 3.83A2 2 0 0 0 9.59 3H4a1 1 0 0 0-1 1v5.59a2 2 0 0 0 .59 1.41l9.58 9.59a2 2 0 0 0 2.82 0l4.6-4.6a2 2 0 0 0 0-2.58z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>;
const HeadsetIcon  = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>;
const ChevDown     = () => <svg width="10" height="10" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>;
const ChevRight    = () => <svg width="10" height="10" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>;
const CalIcon      = () => <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>;
const LogOutIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>;
const MenuIcon     = () => <svg width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>;
const XIcon        = () => <svg width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>;
const MoonIcon     = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>;
const SunIcon      = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>;

const DARK_CSS = String.raw`
.cp-dark { color-scheme: dark; }
.cp-dark .bg-white { background-color: #111827 !important; }
.cp-dark .bg-slate-50 { background-color: #1a2332 !important; }
.cp-dark .bg-slate-100 { background-color: #1f2a3b !important; }
.cp-dark .hover\:bg-slate-50:hover { background-color: #1a2332 !important; }
.cp-dark .text-slate-800 { color: #f1f5f9 !important; }
.cp-dark .text-slate-700 { color: #e2e8f0 !important; }
.cp-dark .text-slate-600 { color: #cbd5e1 !important; }
.cp-dark .text-slate-500 { color: #a3b1c6 !important; }
.cp-dark .text-slate-400 { color: #8494ab !important; }
.cp-dark .hover\:text-slate-800:hover,
.cp-dark .hover\:text-slate-700:hover { color: #f1f5f9 !important; }
.cp-dark .border-black\/\[0\.09\] { border-color: rgba(255,255,255,0.10) !important; }
.cp-dark .border-black\/\[0\.06\] { border-color: rgba(255,255,255,0.07) !important; }
.cp-dark .divide-black\/\[0\.06\] > * { border-color: rgba(255,255,255,0.07) !important; }
.cp-dark .text-cyan-600 { color: #22d3ee !important; }
.cp-dark .text-cyan-500 { color: #22d3ee !important; }
.cp-dark .text-emerald-600 { color: #34d399 !important; }
.cp-dark .text-amber-600 { color: #fbbf24 !important; }
.cp-dark .text-red-600 { color: #f87171 !important; }
.cp-dark input,
.cp-dark select,
.cp-dark textarea { background-color: #0f172a; color: #e2e8f0; }
.cp-dark input::placeholder { color: #64748b; }
`;

const navSections = [
  { label:"Overview",   items:[{ icon:<GridIcon />, label:"Dashboard", path:"/customer/dashboard" }] },
  { label:"Deliveries", items:[
      { icon:<TruckIcon />, label:"My Deliveries", path:"/customer/deliveries" },
      { icon:<CameraIcon />, label:"Delivery Proofs", path:"/customer/delivery-proofs" },
    ] },
  { label:"Billing",    items:[
      { icon:<ReceiptIcon />, label:"Receipts & Balance", path:"/customer/receipts" },
      { icon:<TagIcon />, label:"My Prices", path:"/customer/prices" },
    ] },
  { label:"Support",    items:[{ icon:<HeadsetIcon />, label:"Contact Support", path:"/customer/support" }] },
];

function SidebarNav({ location, navigate, onNavigate }) {
  return (
    <>
      <div
        className="px-[18px] pt-5 pb-4 border-b border-black/[0.09] flex items-center gap-2.5 cursor-pointer"
        onClick={() => onNavigate("/customer/dashboard")}
      >
        <div className="w-[34px] h-[34px] bg-slate-900 border border-cyan-500/20 rounded-md flex items-center justify-center overflow-hidden">
          <img src={logo} alt="Bella Erin logo" className="w-full h-full object-contain p-1" />
        </div>
        <div>
          <div className="text-[15px] font-semibold text-slate-800 tracking-wide">Bella Erin</div>
          <div className="text-[10px] text-slate-400 tracking-widest uppercase">Customer Portal</div>
        </div>
      </div>
      <nav className="py-2.5 flex-1">
        {navSections.map((sec) => (
          <div key={sec.label}>
            <div className="px-[18px] pt-3.5 pb-1 text-[10px] tracking-[0.1em] uppercase text-slate-400 font-medium">{sec.label}</div>
            {sec.items.map((item) => {
              const isActive = location.pathname === item.path;
              return (
                <div
                  key={item.label}
                  onClick={() => onNavigate(item.path)}
                  className={`flex items-center gap-2.5 px-[18px] py-[7px] text-[13px] cursor-pointer transition-all duration-100 border-l-2 ${isActive ? "text-cyan-500 bg-cyan-500/[0.08] border-cyan-500" : "text-slate-500 border-transparent hover:text-slate-800 hover:bg-slate-50"}`}
                >
                  <span className="opacity-80">{item.icon}</span>{item.label}
                </div>
              );
            })}
          </div>
        ))}
      </nav>
    </>
  );
}

export default function CustomerLayout({ pageTitle, breadcrumb, headerActions, children }) {
  const navigate = useNavigate();
  const location = useLocation();
  const user = JSON.parse(localStorage.getItem("user") || "{}");
  const [mobileOpen, setMobileOpen] = useState(false);

  // Night mode preference is remembered on this device.
  const [dark, setDark] = useState(() => {
    try { return localStorage.getItem("cp-theme") === "dark"; } catch { return false; }
  });
  useEffect(() => {
    try { localStorage.setItem("cp-theme", dark ? "dark" : "light"); } catch { /* ignore */ }
  }, [dark]);

  const today = new Date().toLocaleDateString("en-PH", { month:"long", day:"numeric", year:"numeric" });

  const initials = (user?.name || "BE")
    .split(" ").filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase() || "BE";

  const handleLogout = async () => {
    try {
      await fetch("/api/logout", {
        method: "POST",
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${localStorage.getItem("token")}`,
        },
      });
    } catch {
      // ignore, clear the local session regardless
    }
    ["token", "role", "sub_role", "user"].forEach((k) => localStorage.removeItem(k));
    navigate("/customer/login");
  };

  const handleNavigate = (path) => {
    navigate(path);
    setMobileOpen(false);
  };

  const overlay = dark ? "rgba(10,13,18,0.90)" : "rgba(248,250,252,0.88)";

  return (
    <div className={`${dark ? "cp-dark " : ""}flex font-sans text-[13px] leading-relaxed bg-[#0a0d12] text-slate-800 min-h-screen`}>
      <style>{DARK_CSS}</style>

      {/* Mobile drawer */}
      {mobileOpen && (
        <div className="lg:hidden fixed inset-0 z-50 flex">
          <div className="fixed inset-0 bg-slate-950/60" onClick={() => setMobileOpen(false)} />
          <aside className="relative w-[260px] max-w-[85vw] h-full bg-white border-r border-black/[0.09] flex flex-col shadow-2xl">
            <div className="flex items-center justify-end px-3 pt-3">
              <button onClick={() => setMobileOpen(false)} className="text-slate-400 hover:text-slate-700 p-1">
                <XIcon />
              </button>
            </div>
            <SidebarNav location={location} navigate={navigate} onNavigate={handleNavigate} />
            <div className="px-[18px] py-3.5 border-t border-black/[0.09] flex items-center gap-2.5">
              <div className="w-[30px] h-[30px] rounded-full bg-cyan-500/10 border border-cyan-400/25 flex items-center justify-center text-[11px] font-semibold text-cyan-500 flex-shrink-0">{initials}</div>
              <div className="min-w-0">
                <div className="text-xs font-medium text-slate-800 truncate">{user?.name || "Customer"}</div>
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
        </div>
      )}

      {/* Desktop sidebar */}
      <aside className="hidden lg:flex w-[220px] min-h-screen bg-white border-r border-black/[0.09] flex-col flex-shrink-0 sticky top-0 h-screen overflow-y-auto">
        <SidebarNav location={location} navigate={navigate} onNavigate={handleNavigate} />
        <div className="px-[18px] py-3.5 border-t border-black/[0.09] flex items-center gap-2.5">
          <div className="w-[30px] h-[30px] rounded-full bg-cyan-500/10 border border-cyan-400/25 flex items-center justify-center text-[11px] font-semibold text-cyan-500 flex-shrink-0">{initials}</div>
          <div className="min-w-0">
            <div className="text-xs font-medium text-slate-800 truncate">{user?.name || "Customer"}</div>
            <div className="text-[10px] text-slate-400 truncate">{user?.email || ""}</div>
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

        <header className="h-14 bg-white border-b border-black/[0.09] flex items-center px-4 sm:px-6 gap-3 sm:gap-4 flex-shrink-0 sticky top-0 z-10">
          <button
            onClick={() => setMobileOpen(true)}
            className="lg:hidden text-slate-500 hover:text-slate-800 p-1 -ml-1"
            aria-label="Open menu"
          >
            <MenuIcon />
          </button>
          <div className="min-w-0">
            <div className="text-base font-semibold text-slate-800 truncate">{pageTitle}</div>
            <div className="hidden sm:flex text-xs text-slate-400 items-center gap-1">
              Home <ChevRight /> <span className="text-slate-600">{breadcrumb || pageTitle}</span>
            </div>
          </div>
          <div className="flex-1" />
          {headerActions}
          <button
            onClick={() => setDark((d) => !d)}
            title={dark ? "Switch to light mode" : "Switch to night mode"}
            aria-label={dark ? "Switch to light mode" : "Switch to night mode"}
            className="flex items-center gap-1.5 px-2.5 py-1.5 bg-white border border-black/[0.09] rounded-md text-xs text-slate-500 hover:text-slate-800 transition-colors"
          >
            {dark ? <SunIcon /> : <MoonIcon />}
            <span className="hidden sm:inline">{dark ? "Light" : "Night"}</span>
          </button>
          <div className="hidden md:flex items-center gap-1.5 px-3 py-1.5 bg-white border border-black/[0.09] rounded-md text-xs text-slate-500">
            <CalIcon /> {today}
          </div>
          <div className="hidden sm:flex items-center gap-2.5 px-2.5 py-1.5 pl-1.5 bg-white border border-black/[0.09] rounded-md">
            <div className="w-[30px] h-[30px] rounded-full bg-gradient-to-br from-cyan-500/10 to-blue-600/10 border border-cyan-500/20 flex items-center justify-center text-[11px] font-semibold text-cyan-500">{initials}</div>
            <div className="min-w-0 max-w-[140px]">
              <div className="text-xs font-medium text-slate-800 truncate">{user?.name || "Customer"}</div>
            </div>
            <span className="ml-1 text-slate-400"><ChevDown /></span>
          </div>
        </header>

        <main
          className="flex-1 overflow-y-auto p-4 sm:p-6 pb-8 flex flex-col gap-4"
          style={{
            backgroundImage: `linear-gradient(${overlay}, ${overlay}), url(${bgImage})`,
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