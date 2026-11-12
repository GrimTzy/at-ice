import { useEffect, useSyncExternalStore } from "react";

// The "waiting" counts behind the blue badges (side menu, bottom tabs) and the
// bell in the top bar: one store, so they never disagree. Keyed by the page
// path the menu links to ({ "/hr/leave-requests": 3, ... }); the backend
// computes them per role (App\Support\NavCounts).
//
// They arrive with every live-feed poll (lib/live.js calls setNavCounts every
// few seconds), so they update without a refresh and go down as soon as the
// item is handled. `seenPaths` are news pages (Maintenance, the customer, an
// employee's own leave): opening one marks it seen and its badge goes to 0
// (useMarkSeen below). A failed request keeps the counts as they were; no
// badge is better than a wrong one.

let state = { counts: {}, seenPaths: [] };
let stateToken = null;
const listeners = new Set();

const authHeaders = () => ({
  Accept: "application/json",
  "Content-Type": "application/json",
  Authorization: `Bearer ${localStorage.getItem("token") || ""}`,
});

export function setNavCounts(nav) {
  if (!nav || typeof nav !== "object" || typeof nav.counts !== "object") return;
  stateToken = localStorage.getItem("token");
  state = { counts: nav.counts || {}, seenPaths: Array.isArray(nav.seen_paths) ? nav.seen_paths : [] };
  listeners.forEach((fn) => fn());
}

function current() {
  // another user signed in: forget the last user's counts
  const token = localStorage.getItem("token");
  if (stateToken !== token) {
    stateToken = token;
    state = { counts: {}, seenPaths: [] };
  }
  return state;
}

export async function refreshNavCounts() {
  if (!localStorage.getItem("token")) return;
  try {
    const res = await fetch("/api/nav-counts", { headers: authHeaders() });
    if (res.ok) setNavCounts(await res.json());
  } catch {
    // keep the counts as they were
  }
}

export async function markSeen(path) {
  try {
    const res = await fetch("/api/nav-counts/seen", { method: "POST", headers: authHeaders(), body: JSON.stringify({ path }) });
    if (res.ok) setNavCounts(await res.json());
  } catch {
    // tried again on the next visit
  }
}

const subscribe = (fn) => {
  listeners.add(fn);
  return () => { listeners.delete(fn); };
};

function useNavState() {
  const s = useSyncExternalStore(subscribe, current);
  // first screen after sign-in: load now instead of waiting for the next poll
  useEffect(() => {
    if (!Object.keys(current().counts).length) refreshNavCounts();
  }, []);
  return s;
}

// { "/path": n } for the signed-in user (the role is known by the server).
export function useNavCounts() {
  return useNavState().counts;
}

// While a news page is open its badge stays at 0: it is marked seen when it
// opens with news, and again whenever something new arrives on it.
export function useMarkSeen(pathname) {
  const { counts, seenPaths } = useNavState();
  const hasNew = (Number(counts[pathname]) || 0) > 0;
  const isNews = seenPaths.includes(pathname);
  useEffect(() => {
    if (isNews && hasNew) markSeen(pathname);
  }, [pathname, isNews, hasNew]);
}

// The path part of a menu link ("/x?y=1#z" -> "/x").
export const pathOf = (to) => String(to || "").split(/[?#]/)[0];
