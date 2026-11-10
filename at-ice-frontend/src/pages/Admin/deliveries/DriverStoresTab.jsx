import { useCallback, useEffect, useState } from "react";
import { toast } from "../../../lib/toast";
import { api, nextMonday, todayStr, shortDate, storeName, inputCls, labelCls, btnPrimary } from "./utils";

// Driver Stores: Admin hands a Route (a group of stores, Routes tab) to a
// Driver, from a date (usually next week or the next pay period). The stores'
// daily deliveries follow it, on the days of each store's contract.
// Below: Drivers who are off today. Nobody marks this: a Driver still not
// timed in a while after their shift starts, or on approved leave, is off and
// their stops are reassigned (owner, 2026-10-20).
// The page toolbar passes `search` and `type` (customer type) to narrow "Stores per driver".
export default function DriverStoresTab({ drivers, live, onChanged, search = "", type = "" }) {
  const [assignments, setAssignments] = useState([]);
  const [routes, setRoutes] = useState([]);
  const [form, setForm] = useState({ driver_id: "", route_id: "", effective_from: nextMonday(), effective_to: "" });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    try {
      const [a, r] = await Promise.all([api("driver-store-assignments"), api("store-routes")]);
      setAssignments(a);
      setRoutes(r);
    } catch (err) {
      toast.error(err.message);
    }
  }, []);
  useEffect(() => { load(); }, [load, live]);

  const today = todayStr();

  async function save(e) {
    e.preventDefault();
    if (!form.driver_id) return setError("Please select a driver.");
    if (!form.route_id) return setError("Please select a route.");
    if (!form.effective_from) return setError("Pick the date it starts.");
    if (form.effective_from < todayStr()) return setError("The start date cannot be in the past.");
    if (form.effective_to && form.effective_to < form.effective_from) return setError("The end date must be on or after the start date.");
    setSaving(true);
    setError("");
    try {
      const res = await api("driver-route-assignments", { method: "POST", body: { ...form, effective_to: form.effective_to || null } });
      toast.success(res.message);
      setForm({ ...form, route_id: "" });
      load();
      onChanged();
    } catch (err) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  }

  async function endAssignment(a) {
    try {
      await api(`driver-store-assignments/${a.id}`, { method: "DELETE" });
      toast.success(`${a.store?.name || "Store"} was removed from ${a.driver?.name || "the driver"}.`);
      load();
      onChanged();
    } catch (err) {
      toast.error(err.message);
    }
  }

  async function endRoute(r) {
    try {
      await api(`driver-route-assignments/${r.id}`, { method: "DELETE" });
      toast.success(`${r.name} was taken off ${r.driver?.name || "the driver"}.`);
      load();
      onChanged();
    } catch (err) {
      toast.error(err.message);
    }
  }

  async function toggleReserve(dr) {
    try {
      const res = await api(`drivers/${dr.id}/reserve`, { method: "PUT", body: { is_reserve: !dr.is_reserve } });
      toast.success(res.message);
      onChanged();
    } catch (err) {
      toast.error(err.message);
    }
  }

  const offToday = drivers.filter((dr) => dr.status === "off" && (!search || `${dr.name} ${dr.employee_id || ""}`.toLowerCase().includes(search)));
  const narrowed = !!(search || type);
  // A route matches the toolbar when its name, or one of its stores, does; the
  // type filter keeps routes that have a store of that type.
  const routeHit = (r) => (!type || r.stores.some((s) => (s.customer_type || "dealer") === type))
    && (!search || `${r.name} ${r.stores.map((s) => `${s.name} ${s.branch_code || ""}`).join(" ")}`.toLowerCase().includes(search));
  // Stores handed to a driver one by one before routes existed (not on a route)
  const loose = (a) => !a.store?.store_route_id;
  const byDriver = drivers
    .map((dr) => {
      const driverHit = !!search && `${dr.name} ${dr.employee_id || ""}`.toLowerCase().includes(search);
      const driverRoutes = routes.filter((r) => String(r.driver?.id) === String(dr.id) && (driverHit || routeHit(r)));
      const list = assignments.filter((a) => String(a.driver_id) === String(dr.id) && loose(a)
        && (!type || (a.store?.customer_type || "dealer") === type)
        && (!search || driverHit || `${a.store?.name || ""} ${a.store?.chain || ""} ${a.store?.branch_code || ""}`.toLowerCase().includes(search)));
      return { driver: dr, driverRoutes, list, driverHit };
    })
    .filter((x) => !narrowed || x.driverRoutes.length > 0 || x.list.length > 0 || (x.driverHit && !type));

  return (
    <div className="p-[18px] grid gap-4 lg:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
      <form onSubmit={save} className="rounded-xl border border-black/[0.09] p-4 flex flex-col gap-3 self-start" noValidate>
        <div>
          <h3 className="text-[13px] font-semibold text-slate-800">Assign a route to a driver</h3>
          <p className="text-[11.5px] text-slate-500 mt-0.5">Every store on the route goes to this driver from the start date, on the days of its contract. Stores added to the route later follow too. A delivery reassigned because of a leave keeps its new driver.</p>
        </div>
        {error && <div className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[12px] text-red-700">{error}</div>}
        <div>
          <label htmlFor="assign-driver" className={labelCls}>Driver *</label>
          <select id="assign-driver" name="driver_id" value={form.driver_id} onChange={(e) => { setForm({ ...form, driver_id: e.target.value }); setError(""); }} className={inputCls}>
            <option value="">Select driver…</option>
            {drivers.filter((dr) => !dr.is_reserve).map((dr) => <option key={dr.id} value={dr.id}>{dr.name}{dr.employee_id ? ` · ${dr.employee_id}` : ""}</option>)}
          </select>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label htmlFor="assign-from" className={labelCls}>From *</label>
            <input id="assign-from" name="effective_from" type="date" min={today} value={form.effective_from} onChange={(e) => setForm({ ...form, effective_from: e.target.value })} className={inputCls} />
          </div>
          <div>
            <label htmlFor="assign-until" className={labelCls}>Until (optional)</label>
            <input id="assign-until" name="effective_to" type="date" min={form.effective_from} value={form.effective_to} onChange={(e) => setForm({ ...form, effective_to: e.target.value })} className={inputCls} />
          </div>
        </div>
        <div>
          <label htmlFor="assign-route" className={labelCls}>Route *</label>
          <select id="assign-route" name="route_id" value={form.route_id} onChange={(e) => { setForm({ ...form, route_id: e.target.value }); setError(""); }} className={inputCls}>
            <option value="">Select route…</option>
            {routes.map((r) => <option key={r.id} value={r.id}>{r.name} · {r.stores.length} {r.stores.length === 1 ? "store" : "stores"}{r.driver ? ` · now: ${r.driver.name}` : " · no driver"}</option>)}
          </select>
          {routes.length === 0 && <p className="text-[11px] text-slate-400 mt-1">No routes yet. Make one in the Routes tab first.</p>}
        </div>
        <button type="submit" disabled={saving} className={btnPrimary}>{saving ? "Saving…" : "Assign route"}</button>
      </form>

      <div className="flex flex-col gap-4 min-w-0">
        <div className="rounded-xl border border-black/[0.09]">
          <div className="px-4 py-2.5 border-b border-black/[0.09] bg-slate-50 rounded-t-xl text-[12.5px] font-semibold text-slate-700">Routes per driver</div>
          {byDriver.length === 0 && <div className="px-4 py-6 text-[13px] text-slate-400">{narrowed && drivers.length ? "No driver or store matches this filter." : "No drivers yet."}</div>}
          {byDriver.map(({ driver, driverRoutes, list }) => (
            <div key={driver.id} className="px-4 py-3 border-t first:border-t-0 border-black/[0.06]">
              <div className="text-[13px] font-semibold text-slate-800">{driver.name} <span className="font-normal text-slate-400">· {driverRoutes.length} {driverRoutes.length === 1 ? "route" : "routes"}{list.length > 0 && `, ${list.length} other ${list.length === 1 ? "store" : "stores"}`}</span></div>
              {driverRoutes.length === 0 && list.length === 0 && <div className="text-[12px] text-slate-400 mt-1">No route assigned.</div>}
              <ul className="mt-1.5 flex flex-wrap gap-1.5">
                {driverRoutes.map((r) => (
                  <li key={`r${r.id}`} className="flex items-center gap-1.5 pl-2.5 pr-1 py-1 rounded-md bg-slate-50 border border-black/[0.07] text-[12px] text-slate-700">
                    <span>{r.name}</span>
                    <span className="text-[10.5px] text-slate-400">
                      {r.stores.length} {r.stores.length === 1 ? "store" : "stores"} · {r.effective_from && r.effective_from > today ? `from ${shortDate(r.effective_from)}` : "now"}{r.effective_to ? ` to ${shortDate(r.effective_to)}` : ""}
                    </span>
                    <button type="button" onClick={() => endRoute(r)} aria-label={`Take ${r.name} off ${driver.name}`} className="px-1 text-slate-400 hover:text-red-600">×</button>
                  </li>
                ))}
                {list.map((a) => (
                  <li key={a.id} className="flex items-center gap-1.5 pl-2.5 pr-1 py-1 rounded-md bg-slate-50 border border-black/[0.07] text-[12px] text-slate-700">
                    <span>{storeName(a.store)}</span>
                    <span className="text-[10.5px] text-slate-400">
                      {String(a.effective_from).slice(0, 10) > today ? `from ${shortDate(a.effective_from)}` : "now"}{a.effective_to ? ` to ${shortDate(a.effective_to)}` : ""}
                    </span>
                    <button type="button" onClick={() => endAssignment(a)} aria-label={`Remove ${a.store?.name}`} className="px-1 text-slate-400 hover:text-red-600">×</button>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <div className="rounded-xl border border-black/[0.09]">
          <div className="px-4 py-2.5 border-b border-black/[0.09] bg-slate-50 rounded-t-xl">
            <span className="text-[12.5px] font-semibold text-slate-700">Reserve drivers</span>
            <p className="text-[11px] text-slate-500 mt-0.5">A reserve driver has no route. When a driver is off, their stops go to a reserve driver who has timed in first, then to the other drivers.</p>
          </div>
          {drivers.length === 0 && <div className="px-4 py-6 text-[13px] text-slate-400">No drivers yet.</div>}
          <ul>
            {drivers.map((dr) => (
              <li key={dr.id} className="px-4 py-2 border-t first:border-t-0 border-black/[0.06]">
                <label className="flex items-center gap-2 text-[13px] text-slate-800 cursor-pointer">
                  <input name={`reserve-${dr.id}`} type="checkbox" checked={!!dr.is_reserve} onChange={() => toggleReserve(dr)} />
                  <span>{dr.name}</span>
                  {dr.is_reserve && <span className="text-[11px] text-slate-400">reserve</span>}
                </label>
              </li>
            ))}
          </ul>
        </div>

        <div className="rounded-xl border border-black/[0.09]">
          <div className="px-4 py-2.5 border-b border-black/[0.09] bg-slate-50 rounded-t-xl">
            <span className="text-[12.5px] font-semibold text-slate-700">Off today</span>
            <p className="text-[11px] text-slate-500 mt-0.5">Detected automatically: not timed in an hour after the shift starts (Admin › Settings › Rates), or on approved leave. Their stops go to a driver who is free.</p>
          </div>
          {offToday.length === 0 && <div className="px-4 py-6 text-[13px] text-slate-400">Every driver is working today.</div>}
          <ul>
            {offToday.map((dr) => (
              <li key={dr.id} className="px-4 py-2.5 border-t first:border-t-0 border-black/[0.06] flex items-center justify-between gap-3 text-[13px]">
                <span className="text-slate-800">{dr.name}</span>
                <span className="px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 text-[11px] font-semibold">{dr.reason || "Off"}</span>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  );
}
