import { useCallback, useEffect, useState } from "react";
import { toast } from "../../../lib/toast";
import { api, todayStr, shortDate, storeName, inputCls, labelCls, btnPrimary, btnGhost } from "./utils";
import { Modal, StorePicker, PlusIcon } from "./shared";
import Req from "../../../components/Req";
import Pagination from "../../../components/Pagination";
import { usePagination } from "../../../lib/usePagination";

// Routes A to D (client, 2026-11-10): each route has its stores in delivery
// order and one regular Driver (given in the Driver Stores tab). A card per
// route; clicking one lists its stops, which Admin can move up or down. Mall
// tenants share a stop (same stop number) and move together. A store is on
// one route at most. The page toolbar passes `search` (store) and `type`
// (customer type). API: GET/POST routes, PUT routes/{id}, PUT routes/{id}/stops.
export default function RoutesTab({ stores, live, search = "", type = "" }) {
  const [routes, setRoutes] = useState([]);
  const [contracts, setContracts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [picked, setPicked] = useState(null); // route id
  const [form, setForm] = useState(null); // { route } (null route = new) while the window is open

  const load = useCallback(async () => {
    try {
      const [r, k] = await Promise.all([api("routes"), api("delivery-contracts")]);
      setRoutes(r);
      setContracts(k);
    } catch (err) {
      toast.error(err.message);
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => { load(); }, [load, live]);

  const current = routes.find((r) => r.id === picked) || null;
  const groups = current ? stopGroups(current) : [];
  const rows = groups.flatMap((g, gi) => g.map((s) => ({ ...s, stopNo: gi + 1, groupIndex: gi, shared: g.length > 1 })));
  const shown = rows.filter(({ customer: c }) =>
    (!type || (c?.customer_type || "dealer") === type)
    && (!search || `${c?.name || ""} ${c?.branch_code || ""} ${c?.customer_code || ""} ${c?.address || ""}`.toLowerCase().includes(search)));

  const pager = usePagination(shown);

  // A store with a contract but on no active route gets deliveries with no driver.
  const onRoute = new Set(routes.filter((r) => r.is_active).flatMap((r) => r.stops.map((s) => s.customer_id)));
  const today = todayStr();
  const contracted = new Set(contracts.filter((k) => k.status !== "cancelled" && String(k.end_date).slice(0, 10) >= today).flatMap((k) => (k.stores || []).map((s) => s.id)));
  const noRoute = stores.filter((s) => contracted.has(s.id) && !onRoute.has(s.id));

  async function saveStops(route, nextGroups, message) {
    setBusy(true);
    try {
      const res = await api(`routes/${route.id}/stops`, { method: "PUT", body: { stops: stopsPayload(nextGroups) } });
      toast.success(message);
      if (res.warning) toast.info(res.warning);
      await load();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setBusy(false);
    }
  }

  function addToRoute(store, routeId) {
    const route = routes.find((r) => String(r.id) === String(routeId));
    if (!route) return;
    saveStops(route, [...stopGroups(route), [{ customer_id: store.id }]], `${storeName(store)} was added to ${route.name} as the last stop.`);
  }
  function remove(route, row) {
    const next = stopGroups(route).map((g) => g.filter((s) => s.customer_id !== row.customer_id));
    saveStops(route, next, `${storeName(row.customer)} was taken off ${route.name}.`);
  }
  // Moves the row's whole stop (mall tenants together) one place up or down.
  function move(route, row, step) {
    const next = stopGroups(route);
    const to = row.groupIndex + step;
    if (to < 0 || to >= next.length) return;
    [next[row.groupIndex], next[to]] = [next[to], next[row.groupIndex]];
    saveStops(route, next, `${storeName(row.customer)} is now stop ${to + 1} on ${route.name}.`);
  }

  return (
    <div className="p-[18px] flex flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <p className="text-[12px] text-slate-500 max-w-2xl">
          A route is a list of stores in delivery order, driven by one driver (Driver Stores tab). Pick a route to see its stops and move them up or down; Edit to choose which stores are on it.
        </p>
        <button type="button" onClick={() => setForm({ route: null })} className="flex items-center gap-1.5 px-3 py-1.5 bg-cyan-500 hover:bg-cyan-600 text-white text-[12px] font-medium rounded-lg flex-shrink-0">
          <PlusIcon /> New Route
        </button>
      </div>

      {loading && routes.length === 0 && <div className="text-[13px] text-slate-400">Loading…</div>}
      {!loading && routes.length === 0 && <div className="text-[13px] text-slate-400">No routes yet. Press New Route to make one.</div>}

      {routes.length > 0 && (
        <div className="grid gap-2.5 grid-cols-[repeat(auto-fill,minmax(210px,1fr))]">
          {routes.map((r) => {
            const active = picked === r.id;
            return (
              <button
                key={r.id}
                type="button"
                onClick={() => setPicked(active ? null : r.id)}
                aria-expanded={active}
                className={`driver-card text-left rounded-xl border p-3 transition-colors bg-white ${active ? "border-cyan-500 ring-2 ring-cyan-500/40" : "border-black/[0.09] hover:border-black/20"} ${r.is_active ? "" : "opacity-60"}`}
              >
                <div className="driver-card-name text-[13px] font-semibold text-slate-800 truncate">{r.name}</div>
                <div className="text-[11px] text-slate-400">{r.is_active ? (r.driver ? `Driver: ${r.driver.name}` : "No driver yet") : "Inactive"}</div>
                {r.is_active && r.cover && <div className="text-[11px] text-amber-700">Cover: {r.cover.driver.name}, {shortDate(r.cover.from)} – {shortDate(r.cover.to)}</div>}
                <div className="mt-2.5 text-[12px] text-slate-600">
                  <b className="tabular-nums text-slate-800">{r.stop_count}</b> {r.stop_count === 1 ? "stop" : "stops"}
                  {r.customer_count !== r.stop_count && <> · <b className="tabular-nums text-slate-800">{r.customer_count}</b> stores</>}
                </div>
              </button>
            );
          })}
        </div>
      )}

      {noRoute.length > 0 && (
        <div role="status" className="px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-[12px] text-amber-800">
          <b>{noRoute.length}</b> {noRoute.length === 1 ? "store has" : "stores have"} a contract but {noRoute.length === 1 ? "is" : "are"} not on a route, so {noRoute.length === 1 ? "its" : "their"} deliveries get no route driver. Add {noRoute.length === 1 ? "it" : "them"} to a route:
          {/* Scrolls only with many stores: a short list has no scrollbar, and the selects' soft shadows are not clipped */}
          <ul className={`mt-2 ${noRoute.length > 5 ? "max-h-56 overflow-y-auto p-1" : ""}`}>
            {noRoute.map((s) => (
              <li key={s.id} className="py-1.5 flex items-center justify-between gap-3 text-[13px]">
                <span className="text-slate-800 min-w-0 truncate">{storeName(s)}</span>
                <select name="add_to_route" aria-label={`Add ${s.name} to a route`} value="" onChange={(e) => addToRoute(s, e.target.value)} disabled={busy || routes.length === 0} className={`${inputCls} sm:max-w-[170px]`}>
                  <option value="">{routes.length ? "Add to route…" : "Make a route first"}</option>
                  {routes.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                </select>
              </li>
            ))}
          </ul>
        </div>
      )}

      {current && (
        <div className="rounded-xl border border-black/[0.09]">
          <div className="px-4 py-2.5 border-b border-black/[0.09] bg-slate-50 rounded-t-xl flex items-center justify-between gap-2">
            <span className="text-[12.5px] font-semibold text-slate-700">{current.name}: stops in delivery order</span>
            <button type="button" onClick={() => setForm({ route: current })} className={btnGhost}>Edit</button>
          </div>
          <div className="overflow-x-auto lg:overflow-visible">
            <table className="w-full text-[13px]">
              <thead>
                <tr className="text-left text-slate-400 text-[11px] uppercase tracking-widest">
                  <th className="px-3 py-2.5 font-medium w-14">Stop</th>
                  <th className="px-3 py-2.5 font-medium !text-left">Store</th>
                  <th className="px-3 py-2.5 font-medium text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {shown.length === 0 && (
                  <tr><td colSpan={3} className="px-3 py-8 text-center text-slate-400">
                    {current.stops.length ? "No store matches this filter." : "No stores on this route yet. Press Edit to add some."}
                  </td></tr>
                )}
                {pager.pageItems.map((s) => (
                  <tr key={s.customer_id} className="border-t border-black/[0.06]">
                    <td className="px-3 py-2.5 tabular-nums text-slate-500">{s.stopNo}</td>
                    <td className="px-3 py-2.5 !text-left">
                      {/* Name on the left; the store code (and a shared mall stop) sits on the next line under it */}
                      <div className="flex flex-col items-start text-slate-800">
                        <span>{s.customer?.name || `Store #${s.customer_id}`}</span>
                        <span className="text-[11px] text-slate-400">
                          {s.customer?.branch_code || s.customer?.customer_code}
                          {s.shared && s.stop_group ? ` · shares the stop at ${s.stop_group}` : ""}
                        </span>
                      </div>
                    </td>
                    <td className="px-3 py-2.5">
                      <div className="flex justify-end gap-1.5">
                        <button data-no-confirm type="button" onClick={() => move(current, s, -1)} disabled={busy || s.groupIndex === 0} aria-label={`Move ${s.customer?.name} up`} title="Move up" className="px-2 py-1 rounded-md border border-black/[0.09] text-slate-600 text-[11.5px] hover:bg-slate-50 disabled:opacity-40">↑</button>
                        <button data-no-confirm type="button" onClick={() => move(current, s, 1)} disabled={busy || s.groupIndex === groups.length - 1} aria-label={`Move ${s.customer?.name} down`} title="Move down" className="px-2 py-1 rounded-md border border-black/[0.09] text-slate-600 text-[11.5px] hover:bg-slate-50 disabled:opacity-40">↓</button>
                        <button data-no-confirm type="button" onClick={() => remove(current, s)} disabled={busy} className="px-2 py-1 rounded-md border border-black/[0.09] text-red-600 text-[11.5px] hover:bg-red-50 disabled:opacity-40">Remove</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination pager={pager} />
        </div>
      )}

      {form && (
        <RouteForm
          route={form.route}
          routes={routes}
          stores={stores}
          onClose={() => setForm(null)}
          onSaved={(saved) => { setForm(null); setPicked(saved.id); load(); }}
        />
      )}
    </div>
  );
}

// A route's stops grouped by stop number (mall tenants share one), in order.
function stopGroups(route) {
  const groups = [];
  [...(route.stops || [])]
    .sort((a, b) => a.stop_order - b.stop_order || a.id - b.id)
    .forEach((s) => {
      const last = groups[groups.length - 1];
      if (last && last[0].stop_order === s.stop_order) last.push(s);
      else groups.push([s]);
    });
  return groups;
}

// Body of PUT routes/{id}/stops: stops numbered 1, 2, 3… in group order.
function stopsPayload(groups) {
  return groups
    .filter((g) => g.length > 0)
    .flatMap((g, i) => g.map((s) => ({ customer_id: s.customer_id, stop_order: i + 1, stop_group: s.stop_group || null })));
}

// New route, or Edit: name, active, and a checklist of every store. The
// route's code is made by the server and is not shown (only the name is). Stores kept stay in their order; new ones become the last stops.
// A store already on another route is marked, and moves here when ticked.
function RouteForm({ route, routes, stores, onClose, onSaved }) {
  const [name, setName] = useState(route?.name || "");
  const [isActive, setIsActive] = useState(route ? !!route.is_active : true);
  const [storeIds, setStoreIds] = useState((route?.stops || []).map((s) => s.customer_id));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const routeOf = {};
  routes.forEach((r) => r.stops.forEach((s) => { routeOf[s.customer_id] = r; }));

  async function save(e) {
    e.preventDefault();
    if (!name.trim()) return setError("Enter a name for the route.");
    if (name.trim().length > 100) return setError("The route name can be at most 100 characters.");
    setSaving(true);
    try {
      const saved = route
        ? (await api(`routes/${route.id}`, { method: "PUT", body: { name: name.trim(), is_active: isActive } })).route
        : await api("routes", { method: "POST", body: { name: name.trim() } });

      // Kept stores in their order, then the new ones as the last stops
      const picked = new Set(storeIds.map(String));
      const kept = stopGroups(saved).map((g) => g.filter((s) => picked.has(String(s.customer_id))));
      const had = new Set(kept.flat().map((s) => String(s.customer_id)));
      const added = storeIds.filter((id) => !had.has(String(id))).map((id) => [{ customer_id: id }]);
      const changed = added.length > 0 || kept.flat().length !== (saved.stops || []).length;
      let warning = null;
      if (changed) {
        const res = await api(`routes/${saved.id}/stops`, { method: "PUT", body: { stops: stopsPayload([...kept, ...added]) } });
        warning = res.warning;
      }
      toast.success(route ? `${saved.name} was updated.` : `${saved.name} was added.`);
      if (warning) toast.info(warning);
      onSaved(saved);
    } catch (err) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal title={route ? `Edit ${route.name}` : "New route"} onClose={onClose} wide>
      <form onSubmit={save} className="p-5 flex flex-col gap-3.5" noValidate>
        {error && <div role="alert" className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[12px] text-red-700">{error}</div>}
        <div>
          <label htmlFor="route-name" className={labelCls}>Route name<Req /></label>
          <input id="route-name" name="name" required maxLength={100} value={name} onChange={(e) => { setName(e.target.value); setError(""); }} placeholder="e.g. North loop" className={inputCls} />
        </div>
        {route && (
          <label className="flex items-center gap-2 text-[12.5px] text-slate-700 cursor-pointer">
            <input name="is_active" type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
            Active (an inactive route's stores go back to their Driver Stores driver)
          </label>
        )}
        <div>
          <div className={labelCls}>Stores on this route</div>
          <StorePicker
            stores={stores}
            value={storeIds}
            onChange={(ids) => { setStoreIds(ids); setError(""); }}
            note={(s) => (routeOf[s.id] && routeOf[s.id].id !== route?.id ? `Now on ${routeOf[s.id].name}` : "")}
          />
        </div>
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className={btnGhost}>Close</button>
          <button type="submit" disabled={saving} className={btnPrimary}>{saving ? "Saving…" : "Save route"}</button>
        </div>
      </form>
    </Modal>
  );
}
