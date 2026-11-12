import { useCallback, useEffect, useState } from "react";
import { toast } from "../../../lib/toast";
import { api, todayStr, storeName, inputCls, labelCls, btnPrimary, btnGhost } from "./utils";
import { Modal, StorePicker, PlusIcon } from "./shared";
import Req from "../../../components/Req";
import Pagination from "../../../components/Pagination";
import { usePagination } from "../../../lib/usePagination";

// Routes: a named group of stores (Route A, Route B...) that Admin hands to a
// Driver. Same look as the Driver View: a card per route; clicking one lists
// its stores. A store is on one route at most. The page toolbar passes `search`
// (store name) and `type` (customer type).
export default function RoutesTab({ stores, live, search = "", type = "" }) {
  const [routes, setRoutes] = useState([]);
  const [contracts, setContracts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [picked, setPicked] = useState(null); // route id
  const [form, setForm] = useState(null); // { route } (null route = new) while the window is open

  const load = useCallback(async () => {
    try {
      const [r, k] = await Promise.all([api("store-routes"), api("delivery-contracts")]);
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
  const shown = (current?.stores || []).filter((s) =>
    (!type || (s.customer_type || "dealer") === type)
    && (!search || `${s.name} ${s.branch_code || ""} ${s.customer_code || ""} ${s.city || ""}`.toLowerCase().includes(search)));

  const pager = usePagination(shown);

  // A store with a contract but on no route gets deliveries with no driver.
  const onRoute = new Set(routes.flatMap((r) => r.stores.map((s) => s.id)));
  const today = todayStr();
  const contracted = new Set(contracts.filter((k) => k.status !== "cancelled" && String(k.end_date).slice(0, 10) >= today).flatMap((k) => (k.stores || []).map((s) => s.id)));
  const noRoute = stores.filter((s) => contracted.has(s.id) && !onRoute.has(s.id));

  async function addToRoute(store, routeId) {
    const route = routes.find((r) => String(r.id) === String(routeId));
    if (!route) return;
    try {
      await api(`store-routes/${route.id}`, { method: "PUT", body: { store_ids: [...route.stores.map((s) => s.id), store.id] } });
      toast.success(`${storeName(store)} was added to ${route.name}.`);
      load();
    } catch (err) {
      toast.error(err.message);
    }
  }
  async function remove(route, store) {
    try {
      await api(`store-routes/${route.id}`, { method: "PUT", body: { store_ids: route.stores.filter((s) => s.id !== store.id).map((s) => s.id) } });
      toast.success(`${storeName(store)} was taken off ${route.name}.`);
      load();
    } catch (err) {
      toast.error(err.message);
    }
  }

  return (
    <div className="p-[18px] flex flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <p className="text-[12px] text-slate-500 max-w-2xl">
          A route is a group of stores that go to the same driver. Pick a route to see its stores; Edit to choose which stores are on it.
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
                data-dest-trigger
                aria-expanded={active}
                className={`driver-card text-left rounded-xl border p-3 transition-colors bg-white ${active ? "border-cyan-500 ring-2 ring-cyan-500/40" : "border-black/[0.09] hover:border-black/20"}`}
              >
                <div className="driver-card-name text-[13px] font-semibold text-slate-800">{r.name}</div>
                <div className="text-[11px] text-slate-400">{r.driver ? `Driver: ${r.driver.name}` : "No driver yet"}</div>
                <div className="mt-2.5 text-[12px] text-slate-600">
                  <b className="tabular-nums text-slate-800">{r.stores.length}</b> {r.stores.length === 1 ? "store" : "stores"}
                </div>
              </button>
            );
          })}
        </div>
      )}

      {noRoute.length > 0 && (
        <div role="status" className="px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-[12px] text-amber-800">
          <b>{noRoute.length}</b> {noRoute.length === 1 ? "store has" : "stores have"} a contract but {noRoute.length === 1 ? "is" : "are"} not on a route, so {noRoute.length === 1 ? "its" : "their"} deliveries have no driver. Add {noRoute.length === 1 ? "it" : "them"} to a route:
          {/* Scrolls only with many stores: a short list has no scrollbar, and the selects' soft shadows are not clipped */}
          <ul className={`mt-2 ${noRoute.length > 5 ? "max-h-56 overflow-y-auto p-1" : ""}`}>
            {noRoute.map((s) => (
              <li key={s.id} className="py-1.5 flex items-center justify-between gap-3 text-[13px]">
                <span className="text-slate-800 min-w-0 truncate">{storeName(s)}</span>
                <select name="add_to_route" aria-label={`Add ${s.name} to a route`} value="" onChange={(e) => addToRoute(s, e.target.value)} disabled={routes.length === 0} className={`${inputCls} sm:max-w-[170px]`}>
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
            <span className="text-[12.5px] font-semibold text-slate-700">{current.name}: stores</span>
            <button type="button" onClick={() => setForm({ route: current })} className={btnGhost}>Edit</button>
          </div>
          <div className="overflow-x-auto lg:overflow-visible">
            <table className="w-full text-[13px]">
              <thead>
                <tr className="text-left text-slate-400 text-[11px] uppercase tracking-widest">
                  <th className="px-3 py-2.5 font-medium !text-left">Store</th>
                  <th className="px-3 py-2.5 font-medium text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {shown.length === 0 && (
                  <tr><td colSpan={2} className="px-3 py-8 text-center text-slate-400">
                    {current.stores.length ? "No store matches this filter." : "No stores on this route yet. Press Edit to add some."}
                  </td></tr>
                )}
                {pager.pageItems.map((s) => (
                  <tr key={s.id} className="border-t border-black/[0.06]">
                    <td className="px-3 py-2.5 !text-left">
                      {/* Name on the left; the store code sits on the next line under it */}
                      <div className="flex flex-col items-start text-slate-800">
                        <span>{s.name}</span>
                        <span className="text-[11px] text-slate-400">{s.branch_code || s.customer_code}</span>
                      </div>
                    </td>
                    <td className="px-3 py-2.5">
                      <div className="flex justify-end">
                        <button data-no-confirm type="button" onClick={() => remove(current, s)} className="px-2 py-1 rounded-md border border-black/[0.09] text-red-600 text-[11.5px] hover:bg-red-50">Remove</button>
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

// New route, or Edit: the name and a checklist of every store. A store already
// on another route is marked, and moves here when ticked.
function RouteForm({ route, routes, stores, onClose, onSaved }) {
  const [name, setName] = useState(route?.name || "");
  const [storeIds, setStoreIds] = useState((route?.stores || []).map((s) => s.id));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const routeOf = {};
  routes.forEach((r) => r.stores.forEach((s) => { routeOf[s.id] = r; }));

  async function save(e) {
    e.preventDefault();
    if (!name.trim()) return setError("Enter a name for the route.");
    if (name.trim().length > 60) return setError("The route name can be at most 60 characters.");
    if (routes.some((r) => r.id !== route?.id && r.name.trim().toLowerCase() === name.trim().toLowerCase())) return setError("A route with this name already exists.");
    setSaving(true);
    try {
      const body = { name: name.trim(), store_ids: storeIds };
      const saved = route
        ? await api(`store-routes/${route.id}`, { method: "PUT", body })
        : await api("store-routes", { method: "POST", body });
      toast.success(route ? `${saved.name} was updated.` : `${saved.name} was added.`);
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
          <input id="route-name" name="name" required maxLength={50} value={name} onChange={(e) => { setName(e.target.value); setError(""); }} placeholder="e.g. Route A" className={inputCls} />
        </div>
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
