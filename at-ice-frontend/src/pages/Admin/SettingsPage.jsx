import { useEffect, useState } from "react";
import { Navigate, NavLink, useLocation, useParams } from "react-router-dom";
import AdminLayout from "../../components/AdminLayout";
import AccountSettings from "../../components/AccountSettings";
import SystemSettings from "../../components/SystemSettings";
import { ActivityFeed, TipsWidget } from "../../components/Insight";
import { formatDateTime } from "../../lib/time";

// Admin > Settings: one page per item of the Settings sub-menu
// (/admin/settings/account, /admin-pin, /old-records, /changes), with the same
// items as tabs on top. Rates and rules moved to HR > Rates and rules (owner,
// 2026-11-09); changes to them still show under Recent changes.
// Beside the settings: the latest audited changes and reminders.
const PAGES = [
  { key: "account", label: "Account", hint: "Your name, login email and password." },
  // Moved from HR > Rates and rules (owner, 2026-11-10)
  { key: "deliveries", label: "Deliveries", hint: "FIFO dispatch, ice left on the trucks (rolling stock), alerts, delay notices and the plant location." },
  { key: "support", label: "Support tickets", hint: "When a ticket is overdue and how many a customer may have open." },
  { key: "admin-pin", label: "Admin PIN", hint: "The 4-digit PIN that unlocks a locked Attendance Station kiosk." },
  { key: "old-records", label: "Old records", hint: "Inactive employees and old attendance past the retention period." },
  { key: "changes", label: "Recent changes", hint: "Who did what: the latest audited actions by Admin and HR." },
];

// Old links used one long page with #sections.
const OLD_HASH = {
  "#settings-account": "account",
  "#settings-rates": "account",
  "#settings-admin-pin": "admin-pin",
  "#settings-retention": "old-records",
};

// Which audit actions belong to which page
const ACTIONS = {
  account: /password|account|login|profile|session/i,
  deliveries: /setting/i,
  support: /setting/i,
  "admin-pin": /pin|kiosk/i,
  "old-records": /retention|anonym|purge|archive|hold/i,
  changes: /./,
};

const TIPS = {
  account: [
    { title: "Use a strong password", text: "At least 8 characters with upper and lower case, a number and a symbol.", tone: "cyan", icon: "key" },
    { title: "Signed in elsewhere?", text: "Sign out other devices from this page if a phone or computer was lost.", tone: "amber", icon: "shield" },
  ],
  deliveries: [
    { title: "First done, first served", text: "The Driver who finished their list first gets the next queued delivery, oldest order first.", tone: "cyan", icon: "truck" },
    { title: "Ice stays on the truck", text: "With rolling stock on, what is left after a trip is delivered first on the next one.", tone: "violet", icon: "box" },
  ],
  support: [
    { title: "Emergencies are never blocked", text: "An Emergency Ice Shortage ticket goes through even when a customer has reached the open-ticket limit.", tone: "rose", icon: "alert" },
  ],
  "admin-pin": [
    { title: "Only for locked kiosks", text: "Type this 4-digit PIN on a kiosk locked after too many wrong HR PINs.", tone: "amber", icon: "key" },
    { title: "Keep it private", text: "HR has its own 4-digit PIN; this one is the Admin's override.", tone: "rose", icon: "shield" },
  ],
  "old-records": [
    { title: "Download before you delete", text: "Keep a CSV of an old record first; deleting cannot be undone.", tone: "rose", icon: "file" },
    { title: "Anonymize keeps pay history", text: "The employee ID and pay records stay; personal details are cleared.", tone: "violet", icon: "users" },
  ],
  changes: [
    { title: "Every sensitive action is kept", text: "Employee edits, schedules, leave, memos, payroll and support tickets show who did them and when.", tone: "violet", icon: "file" },
    { title: "Private values stay hidden", text: "Government numbers and pay rates show only that they changed, never the values.", tone: "rose", icon: "shield" },
  ],
};

// "Kevin Abad (HR)"
const who = (l) => `${l.user_name || "System"}${l.user_role ? ` (${l.user_role})` : ""}`;

// Admin > Settings > Recent changes: the audit log as a table
function ChangesTable({ logs }) {
  const [q, setQ] = useState("");
  if (logs === null) return <p className="text-[12px] text-slate-500">Loading the audit log…</p>;
  const term = q.trim().toLowerCase();
  const rows = logs.filter((l) => !term || `${l.action_label} ${who(l)} ${l.subject_label || ""}`.toLowerCase().includes(term));
  return (
    <div className="rounded-xl border border-black/[0.09] bg-white overflow-hidden">
      <div className="p-3 border-b border-black/[0.06]">
        <input
          type="search"
          name="changes_search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search by action, person or subject"
          className="w-full sm:w-80 px-3 py-1.5 border border-black/[0.09] rounded-md text-[13px] text-slate-800 outline-none focus:border-cyan-500/50 focus:ring-1 focus:ring-cyan-500/20"
        />
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-[13px]">
          <thead>
            <tr className="text-slate-500 bg-slate-50/60">
              <th className="px-4 py-2.5 font-medium">When</th>
              <th className="px-4 py-2.5 font-medium">Done by</th>
              <th className="px-4 py-2.5 font-medium">Action</th>
              <th className="px-4 py-2.5 font-medium">Subject</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr><td colSpan={4} className="px-4 py-6 text-slate-400">No changes recorded yet.</td></tr>
            ) : rows.map((l) => (
              <tr key={l.id} className="border-t border-black/[0.06]">
                <td className="px-4 py-2.5 text-slate-500 whitespace-nowrap">{formatDateTime(l.created_at, { year: false })}</td>
                <td className="px-4 py-2.5 text-slate-700">{who(l)}</td>
                <td className="px-4 py-2.5 text-slate-800 font-medium">{l.action_label || humanize(l.action)}</td>
                <td className="px-4 py-2.5 text-slate-600">{l.subject_label || "—"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function useAuditLog() {
  const [logs, setLogs] = useState(null);
  useEffect(() => {
    let alive = true;
    fetch("/api/audit-logs", { headers: { Accept: "application/json", Authorization: `Bearer ${localStorage.getItem("token") || ""}` } })
      .then((r) => (r.ok ? r.json() : []))
      .then((d) => { if (alive) setLogs(Array.isArray(d) ? d : []); })
      .catch(() => { if (alive) setLogs([]); });
    return () => { alive = false; };
  }, []);
  return logs;
}

const humanize = (action) => String(action || "").replace(/[._]/g, " ").replace(/^\w/, (c) => c.toUpperCase());

export default function SettingsPage() {
  const { section } = useParams();
  const { hash } = useLocation();
  const logs = useAuditLog();

  if (!section) return <Navigate to={`/admin/settings/${OLD_HASH[hash] || "account"}`} replace />;
  const page = PAGES.find((p) => p.key === section);
  if (!page) return <Navigate to="/admin/settings/account" replace />;

  const changes = (logs || [])
    .filter((l) => ACTIONS[section].test(l.action || ""))
    .map((l) => ({ id: l.id, title: l.action_label || humanize(l.action), meta: `by ${who(l)}${l.subject_label ? ` · ${l.subject_label}` : ""}`, when: formatDateTime(l.created_at, { year: false }), tone: "violet", icon: "file" }));

  return (
    <AdminLayout pageTitle={`Settings · ${page.label}`} breadcrumb={`Settings / ${page.label}`}>
      <div className="grid grid-cols-1 xl:grid-cols-12 gap-4 items-start">
        <div className="xl:col-span-8 flex flex-col gap-5 min-w-0">
          <nav aria-label="Settings pages" className="flex flex-wrap gap-1.5" data-fx-tabs>
            {PAGES.map((p) => (
              <NavLink
                key={p.key}
                to={`/admin/settings/${p.key}`}
                className={({ isActive }) => `px-3 py-1.5 rounded-md text-[12px] font-medium transition-colors ${isActive ? "bg-cyan-500 text-white" : "border border-black/[0.09] bg-white text-slate-600 hover:text-cyan-700"}`}
              >
                {p.label}
              </NavLink>
            ))}
          </nav>
          <p className="-mt-2 text-[12px] text-slate-500">{page.hint}</p>
          {section === "account" ? <AccountSettings /> : section === "changes" ? <ChangesTable logs={logs} /> : <SystemSettings section={section} />}
        </div>
        <aside className="xl:col-span-4 flex flex-col gap-4 min-w-0 xl:sticky xl:top-0">
          <ActivityFeed title="Recent changes" subtitle="From the audit log" items={changes} empty={logs === null ? "Loading the audit log…" : "No changes recorded for this page yet."} max={6} />
          <TipsWidget tips={TIPS[section]} />
        </aside>
      </div>
    </AdminLayout>
  );
}
