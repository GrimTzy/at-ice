import { useCallback, useEffect, useState } from "react";
import { toast } from "../../../lib/toast";
import { api, nextMonday, todayStr, ymd, shortDate, storeName, inputCls, labelCls, btnPrimary } from "./utils";
import Req from "../../../components/Req";

// Driver Stores: Admin gives a Route (its stores in stop order, Routes tab) to
// a Driver (PUT routes/{id}):
//   No end date        the route's regular Driver { driver_id, driver_from }
//   One day / One week a temporary cover { cover_driver_id, cover_from,
//                      cover_to }; when it ends the regular Driver is back
//                      on his own (owner, 2026-11-11)
// The upcoming deliveries of its stores follow; the stores are told. A Driver
// is the regular Driver of one route at most. Reserve drivers (PUT
// drivers/{id}/reserve) have no regular route but may cover one; when a
// Driver is off, a reserve who has timed in takes the whole route if it fits
// the truck limits, otherwise its stops wait for Admin.
// Below: Drivers who are off today. Nobody marks this: a Driver still not
// timed in a while after their shift starts, or on approved leave, is off and
// their stops are reassigned (owner, 2026-10-20).
// The page toolbar passes `search` and `type` (customer type) to narrow "Stores per driver".
// How long an assignment runs (owner, 2026-11-10, from Kevin's change). The
// dates stay hidden until one is picked, then fill in: one day is today; one
// week is today to six days later (both a cover); no end date starts next
// Monday and makes the regular driver. Moving the start moves the end with it.
const LENGTHS = [
  { value: "day", label: "One day" },
  { value: "week", label: "One week" },
  { value: "open", label: "No end date" },
];
const plusDays = (date, n) => { const d = new Date(`${date}T00:00:00`); d.setDate(d.getDate() + n); return ymd(d); };
// The Until date a length gives for a start date ("" = no end date)
const untilFor = (length, from) => (length === "day" ? from : length === "week" ? plusDays(from, 6) : "");
export default function DriverStoresTab({ drivers, live, onChanged, search = "", type = "" }) {
  const [assignments, setAssignments] = useState([]);
  const [routes, setRoutes] = useState([]);
  const [form, setForm] = useState({ driver_id: "", route_id: "", length: "", effective_from: "", effective_to: "" });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  // Reserve flags just saved, shown until the board (drivers) reloads with them
  const [reserveSaved, setReserveSaved] = useState({});
  useEffect(() => { setReserveSaved({}); }, [drivers]);
  const isReserve = (dr) => (dr.id in reserveSaved ? reserveSaved[dr.id] : !!dr.is_reserve);

  const load = useCallback(async () => {
    try {
      const [a, r] = await Promise.all([api("driver-store-assignments"), api("routes")]);
      setAssignments(a);
      // the stores of each route, from its stops
      setRoutes(r.map((route) => ({ ...route, stores: (route.stops || []).map((st) => ({ id: st.customer_id, ...(st.customer || {}) })) })));
    } catch (err) {
      toast.error(err.message);
    }
  }, []);
  useEffect(() => { load(); }, [load, live]);

  const today = todayStr();

  // Picking a length shows the dates and fills them in
  function pickLength(length) {
    const from = length === "open" ? nextMonday() : today;
    setForm({ ...form, length, effective_from: from, effective_to: untilFor(length, from) });
    setError("");
  }
  // A new start moves the Until date with it for one day and one week
  function pickFrom(from) {
    setForm({ ...form, effective_from: from, effective_to: untilFor(form.length, from) });
    setError("");
  }
  const isCover = form.length === "day" || form.length === "week";
  const pickedReserve = drivers.some((dr) => String(dr.id) === String(form.driver_id) && isReserve(dr));

  async function save(e) {
    e.preventDefault();
    if (!form.driver_id) return setError("Please select a driver.");
    if (!form.route_id) return setError("Please select a route.");
    if (!form.length) return setError("Pick how long: one day, one week or no end date.");
    if (!form.effective_from) return setError("Pick the date it starts.");
    if (form.effective_from < todayStr()) return setError("The start date cannot be in the past.");
    if (form.effective_to && form.effective_to < form.effective_from) return setError("The end date must be on or after the start date.");
    if (!isCover && pickedReserve) return setError("A reserve driver can only cover a route for one day or one week.");
    setSaving(true);
    setError("");
    try {
      // One day / One week: a cover, then the regular driver is back; No end date: the regular driver
      const body = isCover
        ? { cover_driver_id: Number(form.driver_id), cover_from: form.effective_from, cover_to: form.effective_to }
        : { driver_id: Number(form.driver_id), driver_from: form.effective_from };
      const res = await api(`routes/${form.route_id}`, { method: "PUT", body });
      toast.success(res.message);
      if (res.warning) toast.info(res.warning);
      setForm({ ...form, driver_id: "", route_id: "", length: "", effective_from: "", effective_to: "" });
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
      await api(`routes/${r.id}`, { method: "PUT", body: { driver_id: null } });
      toast.success(`${r.name} was taken off ${r.driver?.name || "the driver"}. Days without a cover go to the stores' Driver Stores driver, if any.`);
      load();
      onChanged();
    } catch (err) {
      toast.error(err.message);
    }
  }

  // Ends a temporary cover early; the regular driver has the route again
  async function endCover(r) {
    try {
      await api(`routes/${r.id}`, { method: "PUT", body: { cover_driver_id: null } });
      toast.success(`${r.cover?.driver?.name || "The cover"} no longer covers ${r.name}.${r.driver ? ` ${r.driver.name} has it again.` : ""}`);
      load();
      onChanged();
    } catch (err) {
      toast.error(err.message);
    }
  }

  async function toggleReserve(dr) {
    try {
      const next = !isReserve(dr);
      const res = await api(`drivers/${dr.id}/reserve`, { method: "PUT", body: { is_reserve: next } });
      setReserveSaved((m) => ({ ...m, [dr.id]: next }));
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
  // Stores handed to a driver one by one (not on an active route)
  const onRoute = new Set(routes.filter((r) => r.is_active).flatMap((r) => r.stores.map((st) => st.id)));
  const loose = (a) => !onRoute.has(a.store_id);
  const byDriver = drivers
    .map((dr) => {
      const driverHit = !!search && `${dr.name} ${dr.employee_id || ""}`.toLowerCase().includes(search);
      const driverRoutes = routes.filter((r) => String(r.driver?.id) === String(dr.id) && (driverHit || routeHit(r)));
      const covers = routes.filter((r) => r.cover && String(r.cover.driver.id) === String(dr.id) && (driverHit || routeHit(r)));
      const list = assignments.filter((a) => String(a.driver_id) === String(dr.id) && loose(a)
        && (!type || (a.store?.customer_type || "dealer") === type)
        && (!search || driverHit || `${a.store?.name || ""} ${a.store?.chain || ""} ${a.store?.branch_code || ""}`.toLowerCase().includes(search)));
      return { driver: dr, driverRoutes, covers, list, driverHit };
    })
    .filter((x) => !narrowed || x.driverRoutes.length > 0 || x.covers.length > 0 || x.list.length > 0 || (x.driverHit && !type));

  return (
    <div className="p-[18px] grid gap-4 lg:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
      <form onSubmit={save} className="rounded-xl border border-black/[0.09] p-4 flex flex-col gap-3 self-start" noValidate>
        <div>
          <h3 className="text-[13px] font-semibold text-slate-800">Assign a route to a driver</h3>
          <p className="text-[11.5px] text-slate-500 mt-0.5">No end date makes the route's regular driver. One day or one week is a cover: on those days the route's stores go to this driver, then the regular driver is back on his own. A delivery reassigned because of a leave keeps its new driver.</p>
        </div>
        {error && <div className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[12px] text-red-700">{error}</div>}
        <div>
          <label htmlFor="assign-driver" className={labelCls}>Driver<Req /></label>
          <select id="assign-driver" name="driver_id" required value={form.driver_id} onChange={(e) => { setForm({ ...form, driver_id: e.target.value }); setError(""); }} className={inputCls}>
            <option value="">Select driver…</option>
            {drivers.map((dr) => <option key={dr.id} value={dr.id} disabled={isReserve(dr) && form.length === "open"}>{dr.name}{dr.employee_id ? ` · ${dr.employee_id}` : ""}{isReserve(dr) ? " · reserve (cover only)" : ""}</option>)}
          </select>
        </div>
        <div>
          <label htmlFor="assign-route" className={labelCls}>Route<Req /></label>
          <select id="assign-route" name="route_id" required value={form.route_id} onChange={(e) => { setForm({ ...form, route_id: e.target.value }); setError(""); }} className={inputCls}>
            <option value="">Select route…</option>
            {routes.filter((r) => r.is_active).map((r) => <option key={r.id} value={r.id}>{r.name} · {r.stop_count} {r.stop_count === 1 ? "stop" : "stops"}{r.driver ? ` · regular: ${r.driver.name}` : " · no regular driver"}{r.cover ? ` · cover: ${r.cover.driver.name}` : ""}</option>)}
          </select>
          {routes.length === 0 && <p className="text-[11px] text-slate-400 mt-1">No routes yet. Make one in the Routes tab first.</p>}
        </div>
        <div>
          <label htmlFor="assign-length" className={labelCls}>Assign for<Req /></label>
          <select id="assign-length" name="length" required value={form.length} onChange={(e) => pickLength(e.target.value)} className={inputCls}>
            <option value="" disabled>Select how long…</option>
            {LENGTHS.map((l) => <option key={l.value} value={l.value}>{l.label}</option>)}
          </select>
        </div>
        {form.length && (
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label htmlFor="assign-from" className={labelCls}>From<Req /></label>
              <input id="assign-from" name="effective_from" type="date" required min={today} value={form.effective_from} onChange={(e) => pickFrom(e.target.value)} className={inputCls} />
            </div>
            {isCover && (
              <div>
                <label htmlFor="assign-until" className={labelCls}>Until</label>
                <input id="assign-until" name="effective_to" type="date" value={form.effective_to} readOnly aria-readonly="true" aria-describedby="assign-until-hint" className={`${inputCls} cursor-default`} />
              </div>
            )}
            <p id="assign-until-hint" className="col-span-2 -mt-1.5 text-[11px] text-slate-400">
              {form.length === "day" ? "One day: a cover for that day; then the regular driver is back." : form.length === "week" ? "One week: a cover until six days after From; then the regular driver is back." : "The regular driver from this day until changed. Before it, the current regular driver keeps the route."}
            </p>
          </div>
        )}
        <button type="submit" disabled={saving} className={btnPrimary}>{saving ? "Saving…" : "Assign route"}</button>
      </form>

      <div className="flex flex-col gap-4 min-w-0">
        <div className="rounded-xl border border-black/[0.09]">
          <div className="px-4 py-2.5 border-b border-black/[0.09] bg-slate-50 rounded-t-xl text-[12.5px] font-semibold text-slate-700">Routes per driver</div>
          {byDriver.length === 0 && <div className="px-4 py-6 text-[13px] text-slate-400">{narrowed && drivers.length ? "No driver or store matches this filter." : "No drivers yet."}</div>}
          {byDriver.map(({ driver, driverRoutes, covers, list }) => (
            <div key={driver.id} className="px-4 py-3 border-t first:border-t-0 border-black/[0.06]">
              <div className="text-[13px] font-semibold text-slate-800">{driver.name} <span className="font-normal text-slate-400">· {driverRoutes.length} {driverRoutes.length === 1 ? "route" : "routes"}{covers.length > 0 && `, ${covers.length} ${covers.length === 1 ? "cover" : "covers"}`}{list.length > 0 && `, ${list.length} other ${list.length === 1 ? "store" : "stores"}`}</span></div>
              {driverRoutes.length === 0 && covers.length === 0 && list.length === 0 && <div className="text-[12px] text-slate-400 mt-1">No route assigned.</div>}
              <ul className="mt-1.5 flex flex-wrap gap-1.5">
                {driverRoutes.map((r) => (
                  <li key={`r${r.id}`} className="flex items-center gap-1.5 pl-2.5 pr-1 py-1 rounded-md bg-slate-50 border border-black/[0.07] text-[12px] text-slate-700">
                    <span>{r.name}</span>
                    <span className="text-[10.5px] text-slate-400">
                      {r.stop_count} {r.stop_count === 1 ? "stop" : "stops"} · regular{r.driver_from && r.driver_from > today ? ` from ${shortDate(r.driver_from)}` : ""}{r.is_active ? "" : " · inactive"}
                    </span>
                    <button type="button" onClick={() => endRoute(r)} aria-label={`Take ${r.name} off ${driver.name}`} className="px-1 text-slate-400 hover:text-red-600">×</button>
                  </li>
                ))}
                {covers.map((r) => (
                  <li key={`c${r.id}`} className="flex items-center gap-1.5 pl-2.5 pr-1 py-1 rounded-md bg-amber-50 border border-amber-200 text-[12px] text-slate-700">
                    <span>{r.name}</span>
                    <span className="text-[10.5px] text-amber-700">
                      cover {r.cover.from > today ? `from ${shortDate(r.cover.from)} ` : ""}to {shortDate(r.cover.to)}{r.driver ? `, then ${r.driver.name}` : ""}
                    </span>
                    <button type="button" onClick={() => endCover(r)} aria-label={`End ${driver.name}'s cover of ${r.name}`} className="px-1 text-slate-400 hover:text-red-600">×</button>
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
            <p className="text-[11px] text-slate-500 mt-0.5">A reserve driver has no route. When a driver is off, a reserve driver who has timed in takes their whole route if it fits the truck limits. Otherwise the stops are Waiting: assign a driver or reschedule them on the board.</p>
          </div>
          {drivers.length === 0 && <div className="px-4 py-6 text-[13px] text-slate-400">No drivers yet.</div>}
          <ul>
            {drivers.map((dr) => {
              const own = routes.find((r) => String(r.driver?.id) === String(dr.id));
              return (
                <li key={dr.id} className="px-4 py-2 border-t first:border-t-0 border-black/[0.06]">
                  <label className={`flex items-center gap-2 text-[13px] text-slate-800 ${own && !isReserve(dr) ? "cursor-not-allowed" : "cursor-pointer"}`}>
                    <input name={`reserve-${dr.id}`} type="checkbox" checked={isReserve(dr)} disabled={!!own && !isReserve(dr)} onChange={() => toggleReserve(dr)} />
                    <span>{dr.name}</span>
                    {isReserve(dr) && <span className="text-[11px] text-slate-400">reserve</span>}
                    {own && !isReserve(dr) && <span className="text-[11px] text-slate-400">has {own.name}; take it off to make them a reserve</span>}
                  </label>
                </li>
              );
            })}
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
