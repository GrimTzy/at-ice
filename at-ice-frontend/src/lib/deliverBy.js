import { PILL } from "./statusTones";

// "Deliver by" is a deadline (owner, 2026-11-10): the latest time the ice
// reaches the store. The server sends `due_at` and `timing`
// ({ state, minutes }, App\Models\Delivery::timing): delivered at or before the
// time is on time, after it is late; not delivered and past it is overdue.

const duration = (m) => (m >= 60 ? `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60} min` : ""}` : `${m} min`);

// The pill for a delivery's timing, or null when there is nothing to flag
// (no Deliver by time, cancelled, or not due yet).
export function timingPill(timing) {
  if (!timing) return null;
  if (timing.state === "on_time") return { label: "On time", cls: PILL.success };
  if (timing.state === "late") return { label: `Late ${duration(timing.minutes)}`, cls: PILL.danger };
  if (timing.state === "overdue") return { label: `Overdue ${duration(timing.minutes)}`, cls: PILL.danger };
  return null;
}

// "8:00 AM" from a due_at value, in the viewer's local time
export function deliverByTime(dueAt) {
  if (!dueAt) return "";
  return new Date(dueAt).toLocaleTimeString("en-PH", { hour: "numeric", minute: "2-digit" });
}

// "08:00" for a time field
export function deliverByValue(dueAt) {
  if (!dueAt) return "";
  const d = new Date(dueAt);
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}
