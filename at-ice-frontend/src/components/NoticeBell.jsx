import { useEffect, useRef, useState } from "react";
import { Icon } from "./icons";
import { useLiveTick } from "../lib/live";
import { formatDateTime } from "../lib/time";

// The notice bell in every portal's header (QA review, 2026-11-11). The pop-up
// notices disappear after a few seconds; here the ones of the last week that
// reached this user stay listed, and the red count shows the ones not read yet,
// so a handoff between roles (a new item to set limits on, a rejected proof, a
// leave to approve) is not missed. Opening the list marks them read.
const headers = () => ({ Accept: "application/json", Authorization: `Bearer ${localStorage.getItem("token")}` });

export default function NoticeBell({ className = "" }) {
  const [data, setData] = useState({ unread: 0, notices: [] });
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);
  const live = useLiveTick(["*"]);

  // reloads on every live notice; offline it keeps what is shown
  useEffect(() => {
    let alive = true;
    fetch("/api/notices", { headers: headers() })
      .then((res) => (res.ok ? res.json() : null))
      .then((body) => { if (alive && body) setData(body); })
      .catch(() => {});
    return () => { alive = false; };
  }, [live]);

  // close on a click outside or Escape
  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => { if (rootRef.current && !rootRef.current.contains(e.target)) setOpen(false); };
    const onKey = (e) => { if (e.key === "Escape") setOpen(false); };
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onKey);
    return () => { document.removeEventListener("mousedown", onDown); document.removeEventListener("keydown", onKey); };
  }, [open]);

  const toggle = async () => {
    const next = !open;
    setOpen(next);
    // the dots stay on this time so the new ones can be told apart
    if (next && data.unread > 0) {
      setData((d) => ({ ...d, unread: 0 }));
      fetch("/api/notices/read", { method: "POST", headers: headers() }).catch(() => {});
    }
  };

  return (
    <div ref={rootRef} className={`relative ${className}`}>
      <button type="button" data-no-confirm onClick={toggle} aria-expanded={open} aria-haspopup="true"
        aria-label={data.unread ? `Notices, ${data.unread} not read` : "Notices"} title="Notices"
        className="topbar-chip relative inline-flex items-center justify-center h-10 w-10 bg-white border border-black/[0.09] rounded-lg text-slate-500 hover:text-slate-800 transition-colors cursor-pointer">
        <Icon name="bell" size={17} />
        {data.unread > 0 && (
          <span className="notice-count absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-semibold leading-[18px] text-center">
            {data.unread > 99 ? "99+" : data.unread}
          </span>
        )}
      </button>
      {open && (
        // phone: across the screen under the header (the bell is not at the edge,
        // so a right-aligned box ran off the left side); wider screens: under the bell
        <div role="dialog" aria-label="Notices" className="fixed inset-x-3 top-[4.25rem] sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-[22rem] z-50 bg-white border border-black/[0.09] rounded-xl shadow-lg overflow-hidden">
          <div className="px-4 py-2.5 border-b border-black/[0.09] text-[13px] font-semibold text-slate-800">Notices</div>
          {data.notices.length === 0 ? (
            <p className="px-4 py-6 text-center text-[12px] text-slate-400">No notices in the last 7 days.</p>
          ) : (
            <ul className="max-h-[60vh] overflow-y-auto divide-y divide-black/[0.06]">
              {data.notices.map((n) => (
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
  );
}
