import { PILL } from "./statusTones";
import { formatTime, TZ } from "./time";

// A delivery has a time window (QA review, 2026-11-11): `due_from` opens it
// (may be empty on older deliveries) and `due_at`, "Deliver by", is the
// deadline (owner, 2026-11-10). The server sends both and `timing`
// ({ state, minutes }, App\Models\Delivery::timing): delivered before the
// window is early, at or before the deadline on time, after it late; not
// delivered and past the deadline is overdue.

const duration = (m) => (m >= 60 ? `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60} min` : ""}` : `${m} min`);

// The pill for a delivery's timing, or null when there is nothing to flag
// (no Deliver by time, cancelled, or not due yet).
export function timingPill(timing) {
  if (!timing) return null;
  if (timing.state === "on_time") return { label: "On time", cls: PILL.success };
  if (timing.state === "early") return { label: `Early ${duration(timing.minutes)}`, cls: PILL.warning };
  if (timing.state === "late") return { label: `Late ${duration(timing.minutes)}`, cls: PILL.danger };
  if (timing.state === "overdue") return { label: `Overdue ${duration(timing.minutes)}`, cls: PILL.danger };
  return null;
}

// Display and edit deadlines in the same business timezone as the API.
export function deliverByTime(dueAt) {
  if (!dueAt) return "";
  return formatTime(dueAt, { fallback: "" });
}

// "6:00 AM–8:00 AM", or "by 8:00 AM" when the window has no start
export function windowLabel(dueFrom, dueAt) {
  if (!dueAt) return "";
  return dueFrom ? `${deliverByTime(dueFrom)}–${deliverByTime(dueAt)}` : `by ${deliverByTime(dueAt)}`;
}

// "08:00" for a time field
export function deliverByValue(dueAt) {
  if (!dueAt) return "";
  const d = new Date(dueAt);
  if (Number.isNaN(d.getTime())) return "";
  return d.toLocaleTimeString("en-GB", { timeZone: TZ, hour: "2-digit", minute: "2-digit", hourCycle: "h23" });
}
