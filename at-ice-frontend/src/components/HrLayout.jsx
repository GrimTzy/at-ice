// src/components/HrLayout.jsx
//
// Shared sidebar + topbar shell for all HR pages (mirrors AdminLayout.jsx).
//
// Usage:
//   <HrLayout pageTitle="Face Enrollment" breadcrumb="Attendance / Face Enrollment">
//     ...page content...
//   </HrLayout>

import { useNavigate, useLocation } from "react-router-dom";
import logo from "../assets/logo.png";
import bgImage from "../assets/iceproduct.png";

const GridIcon     = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>;
const UsersIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>;
const ClockIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>;
const FileIcon     = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>;
const DollarIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>;
const UserPlusIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="17" y1="11" x2="23" y2="11"/></svg>;
const CalIcon      = () => <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>;
const ChevRight    = () => <svg width="10" height="10" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>;
const LogOutIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>;

// Items without a `path` are not built yet (same convention as AdminLayout)
const navSections = [
  { label: "Overview", items: [{ icon: <GridIcon />, label: "HR Dashboard", path: "/hr/dashboard" }] },
  {
    label: "HRIS",
    items: [
      { icon: <UsersIcon />, label: "Employees", path: "/hr/employees" },
      { icon: <ClockIcon />, label: "Attendance" },
      { icon: <UserPlusIcon />, label: "Face Enrollment", path: "/hr/face-enrollment" },
      { icon: <FileIcon />, label: "Leave Requests" },
      { icon: <DollarIcon />, label: "Payroll" },
      { icon: <CalIcon />, label: "Interviews" },
    ],
  },
];

export default function HrLayout({ pageTitle, breadcrumb, headerActions, children }) {
  const navigate = useNavigate();
  const location = useLocation();
  const user = JSON.parse(localStorage.getItem("user") || "{}");

  const today = new Date().toLocaleDateString("en-PH", { month: "long", day: "numeric", year: "numeric" });

  const handleLogout = () => {
    ["token", "role", "sub_role", "user"].forEach((k) => localStorage.removeItem(k));
    navigate("/hr/login");
  };

  return (
    <div className="flex font-sans text-[13px] leading-relaxed bg-[#0a0d12] text-slate-800 min-h-screen">

      {/* SIDEBAR */}
      <aside className="w-[220px] min-h-screen bg-white border-r border-black/[0.09] flex flex-col flex-shrink-0 sticky top-0 h-screen overflow-y-auto">
        <div
          className="px-[18px] pt-5 pb-4 border-b border-black/[0.09] flex items-center gap-2.5 cursor-pointer"
          onClick={() => navigate("/hr/dashboard")}
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
          <div className="w-[30px] h-[30px] rounded-full bg-cyan-500/10 border border-cyan-400/25 flex items-center justify-center text-[11px] font-semibold text-cyan-500 flex-shrink-0">HR</div>
          <div className="min-w-0">
            <div className="text-xs font-medium text-slate-800 truncate">{user?.name || "HR Staff"}</div>
            <div className="text-[10px] text-slate-400">Human Resources</div>
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

      {/* MAIN */}
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