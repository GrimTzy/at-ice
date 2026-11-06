import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useNavigate } from "react-router-dom";
import logo from "../assets/logo.png";
import { clearSession } from "../lib/auth";
import { useLogoutConfirm } from "../lib/useLogoutConfirm";
import { initialsOf } from "../lib/initials";
import { ROLE_LABEL } from "../lib/mobileUi";
import LeaveRequestButton from "./LeaveRequestButton";
import { MyMemosButton } from "./MyMemos";
import { ScopeStatusRow } from "./NightMode";
import { Icon } from "./icons";

// The one top bar of the Driver, Checker, Salesman and Maintenance screens:
// AT-ICE logo and page title on the left; on the right the
// page's own status (`status`, e.g. the GPS pill) and the menu button. A second
// row holds the live time and date (under the logo) and the Night/Light button
// (under the menu button). The menu button opens a menu with the name, the role,
// Memos, Leave Request and Sign out (which still asks to confirm).
// `back`: a path, shows a back arrow. `children`: more header rows that stay
// stuck with the bar (a search box, summary counts).
export default function MobileHeader({ role, title, back = null, status = null, action = null, children = null }) {
  const navigate = useNavigate();
  const label = ROLE_LABEL[role] || role;
  const user = (() => {
    try { return JSON.parse(localStorage.getItem("user") || "{}"); } catch { return {}; }
  })();

  return (
    <header className="role-top mobile-header flex-shrink-0 sticky top-0 z-40 bg-white border-b border-slate-200">
      <div className="h-14 px-3 flex flex-nowrap items-center justify-between gap-2">
        <div className="flex min-w-0 flex-1 items-center gap-2">
          {back && (
            <button type="button" onClick={() => navigate(back)} aria-label="Back" className="flex-shrink-0 grid place-items-center w-11 h-10 -ml-2 text-slate-500 cursor-pointer">
              <Icon name="chevronLeft" size={20} />
            </button>
          )}
          <div className="brand-logo w-9 h-9 flex-shrink-0 bg-cyan-500/10 border border-cyan-500/20 rounded-lg flex items-center justify-center overflow-hidden">
            <img src={logo} alt="AT-ICE logo" className="w-full h-full object-contain p-1" />
          </div>
          <div className="min-w-0">
            <h1 className="page-title text-[16px] font-bold leading-tight text-slate-900 truncate">{title}</h1>
          </div>
        </div>
        <div className="flex items-center gap-1.5 flex-shrink-0">
          {status}
          {action}
          <ProfileMenu name={user?.name || label} email={user?.email} role={label} initials={initialsOf(user?.name, label.slice(0, 2).toUpperCase())} />
        </div>
      </div>
      <ScopeStatusRow className="mobile-subrow px-3 pt-0 pb-2" />
      {children}
    </header>
  );
}

function ProfileMenu({ name, email, role, initials }) {
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);
  const [askLogout, logoutDialog] = useLogoutConfirm(() => {
    clearSession();
    navigate("/login");
  });

  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => { if (!rootRef.current?.contains(e.target)) setOpen(false); };
    const onKey = (e) => { if (e.key === "Escape") setOpen(false); };
    document.addEventListener("pointerdown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("pointerdown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const close = () => setOpen(false);
  return (
    <div ref={rootRef} className="relative flex-shrink-0">
      {createPortal(logoutDialog, document.querySelector(".ice-scope") || document.body)}
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Menu for ${name}`}
        title={name}
        className="menu-trigger w-10 h-10 flex items-center justify-center text-slate-600 cursor-pointer"
      >
        <Icon name="menu" size={20} />
      </button>
      {/* stays mounted so the Memos sheet it opens survives the menu closing */}
      <div
        role="menu"
        hidden={!open}
        className="profile-menu menu-pop absolute right-0 top-[calc(100%+8px)] w-[min(17rem,calc(100vw-1.5rem))] p-2"
      >
        <div className="flex items-center gap-2.5 px-2 py-2">
          <div className="w-11 h-11 flex-shrink-0 rounded-full bg-cyan-500/10 border border-cyan-400/25 flex items-center justify-center text-[12px] font-semibold text-cyan-500">{initials}</div>
          <div className="min-w-0">
            <div className="text-[14px] font-semibold text-slate-800 truncate">{name}</div>
            {email && <div className="text-[11px] text-slate-400 truncate mt-0.5">{email}</div>}
            <span className="mt-1 inline-flex items-center px-2 py-px rounded-full text-[10px] font-semibold leading-4 bg-cyan-50 text-cyan-700 border border-cyan-200">{role}</span>
          </div>
        </div>
        <div className="profile-sep my-1.5" />
        <MyMemosButton variant="menu" onActivate={close} />
        <LeaveRequestButton variant="menu" onActivate={close} />
        <div className="profile-sep my-1.5" />
        <button
          type="button"
          role="menuitem"
          onClick={() => { close(); askLogout(); }}
          className="profile-item is-danger w-full flex items-center gap-3 min-h-[48px] px-3 text-[14px] font-medium text-red-600 text-left cursor-pointer"
        >
          <Icon name="logout" size={17} />
          <span className="flex-1 min-w-0 truncate">Sign out</span>
        </button>
      </div>
    </div>
  );
}
