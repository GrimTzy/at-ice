import { useEffect, useState } from "react";
import { useLiveTick } from "./live";

// Counts for the blue badges in the side menu, keyed by the page path the menu
// links to (GET /api/nav-counts/admin or /hr, NavCountController). They reload
// when a live notice for one of those pages arrives, and every two minutes.
// A failed request leaves the badges as they were; no badge is better than a
// wrong one.
const REFRESH_MS = 120000;
const LIVE_MODULES = ["stock-out", "support", "leave", "applicants", "interviews"];

export function useNavCounts(role) {
  const [counts, setCounts] = useState({});
  const live = useLiveTick(LIVE_MODULES);

  useEffect(() => {
    if (!role) return undefined;
    let cancelled = false;
    const load = () => fetch(`/api/nav-counts/${role}`, {
      headers: { Accept: "application/json", Authorization: `Bearer ${localStorage.getItem("token") || ""}` },
    })
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => { if (!cancelled && data && typeof data === "object") setCounts(data); })
      .catch(() => {});
    load();
    const timer = window.setInterval(load, REFRESH_MS);
    return () => { cancelled = true; window.clearInterval(timer); };
  }, [role, live]);

  return counts;
}
