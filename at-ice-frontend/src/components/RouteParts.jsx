import { kmLabel } from "../lib/route";

// Small parts shared by the Driver and the Salesman route screens, so both
// show a stop the same way (owner, 2026-10-20: same route on both screens).

// The stop numbers only suggest an order (QA review, 2026-11-11): the system
// does not make the driver follow them.
export function RouteOrderNote() {
  return (
    <p className="text-[12px] text-slate-500 leading-snug px-1">
      Stops are numbered from the nearest to the plant as a suggested order. You may go in any order, as long as every store gets its delivery.
    </p>
  );
}

// "Stop 1 · 1.2 km from plant" (nearest the plant is stop 1).
export function StopMeta({ delivery, className = "" }) {
  const km = kmLabel(delivery.distance_km);
  if (!delivery.stop_no && !km) return null;
  return (
    <div className={`flex items-center gap-1.5 flex-wrap ${className}`}>
      {delivery.stop_no && (
        <span title="Suggested order: nearest the plant first" className="inline-flex items-center px-2 py-0.5 rounded-full bg-cyan-100 border border-cyan-200 text-cyan-800 text-[11px] font-semibold">
          Stop {delivery.stop_no}
        </span>
      )}
      {km && <span className="text-[12px] text-slate-500">{km}</span>}
      {delivery.distance_km == null && <span className="text-[12px] text-amber-800">No map pin</span>}
    </div>
  );
}

// Who has pressed Arrived at the store: the Driver and the Salesman.
export function ArrivalChips({ arrival, driverLabel = "Driver", salesmanLabel = "Salesman" }) {
  const chip = (done, label) => (
    <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full border text-[11px] font-semibold ${done
      ? "bg-emerald-100 border-emerald-200 text-emerald-800"
      : "bg-amber-100 border-amber-200 text-amber-800"}`}>
      {done ? "✓" : "…"} {label} {done ? "arrived" : "not yet"}
    </span>
  );
  return (
    <div className="flex items-center gap-2 flex-wrap" aria-label="Arrival at the store">
      {chip(!!arrival?.driver, driverLabel)}
      {chip(!!arrival?.salesman, salesmanLabel)}
    </div>
  );
}
