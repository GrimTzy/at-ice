import { THEME_KEY } from "../lib/theme";

// Shown while a page's code downloads (pages load on demand, see App.jsx).
// Quiet on purpose: it fades in only after a short delay, so a fast load shows
// nothing at all; then a soft pressed-in ring on the page material, pulsing
// slowly. It sits outside the page's theme scope, so it reads the saved theme
// itself (the same choice the layouts use) and never flashes the wrong color.
function savedDark() {
  try {
    const saved = localStorage.getItem(THEME_KEY);
    if (saved) return saved === "dark";
  } catch { /* ignore */ }
  return window.matchMedia?.("(prefers-color-scheme: dark)").matches ?? false;
}

export default function RouteLoading() {
  return (
    <div className={`route-loading ${savedDark() ? "neu-dark" : ""}`} role="status" aria-live="polite">
      <span className="route-loading-orb" aria-hidden="true" />
      <span className="sr-only">Loading…</span>
    </div>
  );
}
