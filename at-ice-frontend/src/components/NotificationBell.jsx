import { useEffect, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { ADMIN_NAV, HR_NAV, CUSTOMER_NAV } from "../lib/nav";
import { ROLE_NAV } from "../lib/mobileUi";
import { pathOf, useMarkSeen, useNavCounts } from "../lib/navCounts";
import { useLiveTick } from "../lib/live";
import { formatDateTime } from "../lib/time";
import { Icon } from "./icons";

// The one bell in the top bar of every role, with two tabs:
//   Waiting for you  what is waiting where, from the same counts the side menu
//                    and bottom tabs show (lib/navCounts.js), so they never
//                    disagree. Its total is the number on the bell. A row
//                    opens that page.
//   Notices          the pop-up notices of the last week that reached this
//                    user (GET /api/notices), so a handoff between roles is
//                    not missed after the pop-up is gone. Opening the tab
//                    marks them read (POST /api/notices/read); the tab shows
//                    how many are not read, and the bell a small dot.

// Page path -> label, from the menus, plus pages that have no menu item.
const LABELS = (() => {
  const out = { "/driver/deliveries": "My deliveries", "/my-leave": "My leave requests" };
  const walk = (items, parent = "") => items.forEach((it) => {
    if (it.children) walk(it.children, it.label);
    else if (it.to && !out[pathOf(it.to)]) out[pathOf(it.to)] = parent && parent !== it.label ? `${parent}: ${it.label}` : it.label;
  });
  walk(ADMIN_NAV);
  walk(HR_NAV);
  walk(CUSTOMER_NAV);
  Object.entries(ROLE_NAV).forEach(([role, tabs]) => tabs.forEach((t) => {
    const p = pathOf(t.to);
    if (!out[p]) out[p] = role === "salesman" ? "Deliveries to confirm" : t.label;
  }));
  return out;
})();

const labelForPath = (path) => LABELS[path] || path;

const headers = () => ({ Accept: "application/json", Authorization: `Bearer ${localStorage.getItem("token")}` });

const cap = (n) => (Number(n) > 99 ? "99+" : n);

export default function NotificationBell({ className = "" }) {
  const counts = useNavCounts();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [tab, setTab] = useState("waiting");
  const [notices, setNotices] = useState({ unread: 0, notices: [] });
  const rootRef = useRef(null);
  const live = useLiveTick(["*"]);

  const items = Object.entries(counts).filter(([, n]) => Number(n) > 0);
  const total = items.reduce((s, [, n]) => s + Number(n), 0);
  const unread = Number(notices.unread) || 0;

  // reloads on every live notice; offline it keeps what is shown
  useEffect(() => {
    let alive = true;
    fetch("/api/notices", { headers: headers() })
      .then((res) => (res.ok ? res.json() : null))
      .then((body) => { if (alive && body) setNotices(body); })
      .catch(() => {});
    return () => { alive = false; };
  }, [live]);

  // close on a click outside or Escape
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

  // Showing the notices marks them read. The dots stay on this time so the
  // new ones can be told apart.
  const showTab = (next) => {
    setTab(next);
    if (next === "notices" && unread > 0) {
      setNotices((d) => ({ ...d, unread: 0 }));
      fetch("/api/notices/read", { method: "POST", headers: headers() }).catch(() => {});
    }
  };

  const toggle = () => {
    const next = !open;
    setOpen(next);
    // nothing waiting but new notices: open on the notices
    if (next) showTab(total === 0 && unread > 0 ? "notices" : "waiting");
  };

  const label = [
    total ? `${total} waiting` : "nothing waiting",
    unread ? `${unread} notices not read` : null,
  ].filter(Boolean).join(", ");

  const tabCls = (on) => `flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 text-[12.5px] font-semibold cursor-pointer border-b-2 ${on ? "border-blue-500 text-slate-800" : "border-transparent text-slate-500 hover:text-slate-700"}`;

  return (
    <div ref={rootRef} className={`relative flex-shrink-0 ${className}`}>
      <button
        type="button"
        data-no-confirm
        onClick={toggle}
        aria-label={`Notifications: ${label}`}
        title="Notifications"
        aria-expanded={open}
        aria-haspopup="true"
        className="topbar-chip relative inline-flex items-center justify-center h-10 w-10 bg-white border border-black/[0.09] rounded-lg text-slate-500 hover:text-slate-800 cursor-pointer"
      >
        <Icon name="bell" size={17} />
        {total > 0 && (
          <span className="notice-count absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-blue-500 text-white text-[10px] font-bold leading-[18px] text-center">
            {cap(total)}
          </span>
        )}
        {total === 0 && unread > 0 && (
          <span aria-hidden="true" className="absolute top-1 right-1 w-2.5 h-2.5 rounded-full bg-red-600 ring-2 ring-white" />
        )}
      </button>
      {open && (
        // phone: across the screen under the header (the bell is not at the edge,
        // so a right-aligned box ran off the left side); wider screens: under the bell
        <div role="dialog" aria-label="Notifications" className="fixed inset-x-3 top-[4.25rem] sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-[22rem] z-50 bg-white border border-black/[0.09] rounded-xl shadow-lg overflow-hidden">
          <div role="tablist" aria-label="Notifications" className="flex border-b border-black/[0.09]">
            <button type="button" role="tab" data-no-confirm id="bell-tab-waiting" aria-selected={tab === "waiting"} aria-controls="bell-panel-waiting" onClick={() => showTab("waiting")} className={tabCls(tab === "waiting")}>
              Waiting for you
              {total > 0 && <span className="nav-badge">{cap(total)}</span>}
            </button>
            <button type="button" role="tab" data-no-confirm id="bell-tab-notices" aria-selected={tab === "notices"} aria-controls="bell-panel-notices" onClick={() => showTab("notices")} className={tabCls(tab === "notices")}>
              Notices
              {unread > 0 && <span className="min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-semibold leading-[18px] text-center">{cap(unread)}</span>}
            </button>
          </div>

          {tab === "waiting" && (
            <div role="tabpanel" id="bell-panel-waiting" aria-labelledby="bell-tab-waiting" className="py-1.5 max-h-[60vh] overflow-y-auto">
              {items.length === 0 && <p className="px-4 py-6 text-center text-[12px] text-slate-400">Nothing waiting right now.</p>}
              {items.map(([path, n]) => (
                <button
                  key={path}
                  type="button"
                  data-no-confirm
                  onClick={() => { setOpen(false); navigate(path); }}
                  className="w-full flex items-center gap-3 px-4 py-2 text-left text-[13px] text-slate-700 hover:bg-slate-50 cursor-pointer"
                >
                  <span className="flex-1 min-w-0 truncate">{labelForPath(path)}</span>
                  <span className="nav-badge">{cap(n)}</span>
                </button>
              ))}
            </div>
          )}

          {tab === "notices" && (
            <div role="tabpanel" id="bell-panel-notices" aria-labelledby="bell-tab-notices">
              {notices.notices.length === 0 ? (
                <p className="px-4 py-6 text-center text-[12px] text-slate-400">No notices in the last 7 days.</p>
              ) : (
                <ul className="max-h-[60vh] overflow-y-auto divide-y divide-black/[0.06]">
                  {notices.notices.map((n) => (
                    <li key={n.id} className="px-4 py-2.5 flex gap-2.5">
                      <span aria-hidden="true" className={`mt-1.5 w-2 h-2 rounded-full flex-shrink-0 ${n.unread ? "bg-cyan-500" : "bg-transparent"}`} />
                      <span className="min-w-0">
                        <span className="block text-[12.5px] text-slate-700 break-words">{n.message}</span>
                        <span className="block text-[11px] text-slate-400 mt-0.5">{formatDateTime(n.created_at, { year: false })}{n.unread ? " · new" : ""}</span>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// Mounted once (App.jsx): a news page that is open is marked seen, so its
// badge and the bell go down (lib/navCounts.js useMarkSeen).
export function NavSeen() {
  const { pathname } = useLocation();
  useMarkSeen(pathname);
  return null;
}
