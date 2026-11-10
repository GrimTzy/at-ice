// Helpers for Admin > Deliveries (Driver View, Store View, Contracts,
// Driver Stores): API calls, dates and labels. Components are in shared.jsx.
import { PILL } from "../../../lib/statusTones";
import { deliverByValue } from "../../../lib/deliverBy";
import { todayManila } from "../../../lib/time";

export const API_BASE = "/api";

function token() { return localStorage.getItem("token") || ""; }
export function authHeaders() {
  return { "Content-Type": "application/json", Accept: "application/json", Authorization: `Bearer ${token()}` };
}

// fetch + JSON; throws an Error with the backend's message.
export async function api(path, { method = "GET", body } = {}) {
  const res = await fetch(`${API_BASE}/${path}`, {
    method, headers: authHeaders(), body: body === undefined ? undefined : JSON.stringify(body),
  });
  const data = await res.json().catch(() => null);
  if (!res.ok) throw new Error(data?.message || `Request failed (${res.status})`);
  return data;
}

// The time now as HH:MM (24-hour), for "Deliver by" fields on today's date.
export function nowHm() {
  return deliverByValue(new Date());
}

// Dates and validation follow the business calendar, regardless of device timezone.
export const todayStr = () => todayManila();
export function ymd(d) {
  const pad = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
// The next Monday (or today when today is Monday): the usual start of a new assignment.
export function nextMonday() {
  const d = new Date(`${todayStr()}T12:00:00`);
  d.setDate(d.getDate() + ((8 - d.getDay()) % 7));
  return ymd(d);
}
export const shortDate = (v) => (v ? new Date(`${String(v).slice(0, 10)}T00:00:00`).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" }) : "—");
export const timeOf = (v) => {
  if (!v) return "";
  const t = String(v).includes("T") || String(v).includes(" ") ? String(v).replace(" ", "T") : `1970-01-01T${v}`;
  return new Date(t).toLocaleTimeString("en-PH", { hour: "numeric", minute: "2-digit" });
};

export const PRODUCT_TYPES = [
  { value: "bags",       label: "Bags (5kg)" },
  { value: "small_sack", label: "Small Sack (30kg)" },
  { value: "big_sack",   label: "Big Sack (50kg)" },
];
export const productLabel = (type) => PRODUCT_TYPES.find((p) => p.value === type)?.label || type;

export const DAYS = [
  { value: "mon", label: "Mon" }, { value: "tue", label: "Tue" }, { value: "wed", label: "Wed" },
  { value: "thu", label: "Thu" }, { value: "fri", label: "Fri" }, { value: "sat", label: "Sat" },
  { value: "sun", label: "Sun" },
];
export function daysLabel(days = []) {
  const all = DAYS.map((d) => d.value);
  if (all.every((d) => days.includes(d))) return "Mon–Sun";
  if (all.slice(0, 6).every((d) => days.includes(d)) && days.length === 6) return "Mon–Sat";
  if (all.slice(0, 5).every((d) => days.includes(d)) && days.length === 5) return "Mon–Fri";
  return DAYS.filter((d) => days.includes(d.value)).map((d) => d.label).join(", ");
}

// Delivery status pill. One meaning per color everywhere (owner, 2026-10-20,
// same as StatusBadge on the phone screens): waiting amber, queued violet,
// on the way deep cyan, done emerald, cancelled gray. A pill, not a solid
// block, so a status never looks like a button.
export const STATUS_STYLE = {
  pending:    { bg: "bg-amber-50 border-amber-200", text: "text-amber-700", label: "Pending" },
  queued:     { bg: "bg-violet-50 border-violet-200", text: "text-violet-700", label: "Waiting" },
  in_transit: { bg: "bg-cyan-50 border-cyan-200", text: "text-cyan-800", label: "In Transit" },
  delivered:  { bg: "bg-emerald-50 border-emerald-200", text: "text-emerald-700", label: "Delivered" },
  cancelled:  { bg: "bg-slate-100 border-slate-200", text: "text-slate-700", label: "Cancelled" },
};

// Driver status on the board: worked out from attendance, deliveries and
// leave marks (backend). Only known for today.
export const DRIVER_STATUS = {
  available:   { dot: "bg-emerald-500", pill: PILL.success, label: "Available" },
  in_transit:  { dot: "bg-cyan-600",    pill: PILL.progress, label: "In transit" },
  unavailable: { dot: "bg-slate-400",   pill: PILL.neutral, label: "Not timed in" },
  off:         { dot: "bg-rose-500",    pill: PILL.danger, label: "Off" },
};

// Driver View order and status filter (owner, 2026-11-09): timed in first,
// then in transit, not timed in, on leave (an approved leave, emergency leave
// or rest day), then off (absent, timed out, no employee record). Inside a
// group the Driver who finished their list first comes first (FIFO), then by
// name. Only today has a status, except a leave, which is known ahead.
export const DRIVER_GROUPS = [
  { value: "timed_in", label: "Timed in" },
  { value: "in_transit", label: "In transit" },
  { value: "not_timed_in", label: "Not timed in" },
  { value: "on_leave", label: "On leave" },
  { value: "off", label: "Off" },
];
export function driverGroup(d) {
  if (d.status === "available") return "timed_in";
  if (d.status === "in_transit") return "in_transit";
  if (/not timed in/i.test(d.reason || "")) return "not_timed_in";
  if (d.status === "off" && /leave|rest day/i.test(d.reason || "")) return "on_leave";
  if (d.status === "off" || d.status === "unavailable") return "off";
  return null;
}
const GROUP_RANK = Object.fromEntries(DRIVER_GROUPS.map((g, i) => [g.value, i]));
export function byDriverGroup(a, b) {
  return (GROUP_RANK[driverGroup(a)] ?? 9) - (GROUP_RANK[driverGroup(b)] ?? 9)
    || String(a.trip_completed_at || "~").localeCompare(String(b.trip_completed_at || "~"))
    || String(a.name || "").localeCompare(String(b.name || ""));
}

export function storeName(c) {
  if (!c) return "—";
  return c.branch_code ? `${c.name} · ${c.branch_code}` : c.name;
}

export const inputCls = "w-full bg-slate-50 border border-black/[0.09] rounded-md px-2.5 py-1.5 text-[13px] outline-none focus:border-cyan-500";
export const labelCls = "text-[11px] text-slate-500 mb-1 block";
export const btnPrimary = "px-3.5 py-1.5 rounded-md bg-cyan-500 hover:bg-cyan-600 text-white text-[12px] font-medium disabled:opacity-50";
export const btnGhost = "px-3.5 py-1.5 rounded-md border border-black/[0.09] text-slate-600 text-[12px] hover:bg-slate-50";
