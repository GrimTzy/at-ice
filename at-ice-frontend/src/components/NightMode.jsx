import { useEffect, useState } from "react";

export const THEME_KEY = "atice-theme";

export const DARK_CSS = String.raw`
.cp-dark { color-scheme: dark; }

/* Surfaces */
.cp-dark .bg-white { background-color: #111827 !important; }
.cp-dark .bg-white\/95,
.cp-dark .bg-white\/90 { background-color: rgba(17,24,39,0.95) !important; }
.cp-dark .bg-white\/80 { background-color: rgba(17,24,39,0.85) !important; }
.cp-dark .bg-slate-50 { background-color: #1a2332 !important; }
.cp-dark .bg-slate-100 { background-color: #1f2a3b !important; }
.cp-dark .bg-slate-200 { background-color: #273449 !important; }
.cp-dark .hover\:bg-slate-50:hover,
.cp-dark .active\:bg-slate-50:active { background-color: #1a2332 !important; }
.cp-dark .hover\:bg-slate-100:hover,
.cp-dark .active\:bg-slate-100:active { background-color: #1f2a3b !important; }
.cp-dark .active\:bg-slate-200:active { background-color: #273449 !important; }

/* Text */
.cp-dark .text-slate-900 { color: #f8fafc !important; }
.cp-dark .text-slate-800 { color: #f1f5f9 !important; }
.cp-dark .text-slate-700 { color: #e2e8f0 !important; }
.cp-dark .text-slate-600 { color: #cbd5e1 !important; }
.cp-dark .text-slate-500 { color: #a3b1c6 !important; }
.cp-dark .text-slate-400 { color: #8494ab !important; }
.cp-dark .text-slate-300 { color: #64748b !important; }
.cp-dark .hover\:text-slate-800:hover,
.cp-dark .hover\:text-slate-700:hover,
.cp-dark .active\:text-slate-600:active { color: #f1f5f9 !important; }

/* Borders and dividers */
.cp-dark .border-black\/\[0\.10\],
.cp-dark .border-black\/\[0\.09\],
.cp-dark .border-black\/\[0\.08\] { border-color: rgba(255,255,255,0.10) !important; }
.cp-dark .border-black\/\[0\.07\],
.cp-dark .border-black\/\[0\.06\] { border-color: rgba(255,255,255,0.07) !important; }
.cp-dark .divide-black\/\[0\.06\] > * { border-color: rgba(255,255,255,0.07) !important; }
.cp-dark .border-slate-100,
.cp-dark .border-slate-200 { border-color: rgba(255,255,255,0.10) !important; }
.cp-dark .divide-slate-100 > * { border-color: rgba(255,255,255,0.07) !important; }

/* Accent text */
.cp-dark .text-cyan-500,
.cp-dark .text-cyan-600 { color: #22d3ee !important; }
.cp-dark .text-cyan-700,
.cp-dark .text-cyan-800 { color: #67e8f9 !important; }
.cp-dark .text-emerald-600 { color: #34d399 !important; }
.cp-dark .text-emerald-700 { color: #6ee7b7 !important; }
.cp-dark .text-green-500,
.cp-dark .text-green-600 { color: #4ade80 !important; }
.cp-dark .text-amber-500,
.cp-dark .text-amber-600 { color: #fbbf24 !important; }
.cp-dark .text-amber-700 { color: #fcd34d !important; }
.cp-dark .text-red-500,
.cp-dark .text-red-600 { color: #f87171 !important; }
.cp-dark .text-red-700 { color: #fca5a5 !important; }
.cp-dark .text-blue-500,
.cp-dark .text-blue-600 { color: #60a5fa !important; }
.cp-dark .text-violet-600,
.cp-dark .text-purple-600 { color: #a78bfa !important; }

/* Tinted backgrounds (status badges, notice boxes) */
.cp-dark .bg-emerald-50 { background-color: rgba(16,185,129,0.10) !important; }
.cp-dark .bg-emerald-100 { background-color: rgba(16,185,129,0.18) !important; }
.cp-dark .bg-green-50 { background-color: rgba(34,197,94,0.10) !important; }
.cp-dark .bg-green-100 { background-color: rgba(34,197,94,0.18) !important; }
.cp-dark .bg-amber-50 { background-color: rgba(245,158,11,0.10) !important; }
.cp-dark .bg-amber-100 { background-color: rgba(245,158,11,0.18) !important; }
.cp-dark .bg-red-50 { background-color: rgba(239,68,68,0.10) !important; }
.cp-dark .bg-red-100 { background-color: rgba(239,68,68,0.18) !important; }
.cp-dark .bg-cyan-50 { background-color: rgba(6,182,212,0.10) !important; }
.cp-dark .bg-cyan-100 { background-color: rgba(6,182,212,0.18) !important; }
.cp-dark .bg-blue-50 { background-color: rgba(59,130,246,0.10) !important; }
.cp-dark .bg-blue-100 { background-color: rgba(59,130,246,0.18) !important; }
.cp-dark .bg-violet-50,
.cp-dark .bg-purple-50 { background-color: rgba(139,92,246,0.10) !important; }
.cp-dark .bg-violet-100,
.cp-dark .bg-purple-100 { background-color: rgba(139,92,246,0.18) !important; }
.cp-dark .hover\:bg-emerald-50:hover { background-color: rgba(16,185,129,0.14) !important; }
.cp-dark .hover\:bg-red-50:hover { background-color: rgba(239,68,68,0.14) !important; }
.cp-dark .hover\:bg-cyan-50:hover { background-color: rgba(6,182,212,0.14) !important; }

/* Tinted borders */
.cp-dark .border-emerald-200 { border-color: rgba(52,211,153,0.35) !important; }
.cp-dark .border-green-100,
.cp-dark .border-green-200 { border-color: rgba(74,222,128,0.30) !important; }
.cp-dark .border-amber-200 { border-color: rgba(251,191,36,0.35) !important; }
.cp-dark .border-red-100,
.cp-dark .border-red-200 { border-color: rgba(248,113,113,0.35) !important; }
.cp-dark .border-cyan-200 { border-color: rgba(34,211,238,0.35) !important; }
.cp-dark .border-blue-100,
.cp-dark .border-blue-200 { border-color: rgba(96,165,250,0.30) !important; }
.cp-dark .hover\:border-red-300:hover { border-color: rgba(248,113,113,0.55) !important; }

/* Form fields */
.cp-dark input,
.cp-dark select,
.cp-dark textarea { background-color: #0f172a; color: #e2e8f0; }
.cp-dark input::placeholder,
.cp-dark textarea::placeholder { color: #64748b; }
.cp-dark option { background-color: #0f172a; color: #e2e8f0; }
`;

// Remembers the choice on this device (shared by all staff modules).
export function useNightMode(storageKey = THEME_KEY) {
  const [dark, setDark] = useState(() => {
    try { return localStorage.getItem(storageKey) === "dark"; } catch { return false; }
  });
  useEffect(() => {
    try { localStorage.setItem(storageKey, dark ? "dark" : "light"); } catch { /* ignore */ }
  }, [dark, storageKey]);
  return [dark, setDark];
}

// Background overlay for the ice image behind the page content
export const nightOverlay = (dark) => (dark ? "rgba(10,13,18,0.90)" : "rgba(248,250,252,0.88)");

export function NightStyle() {
  return <style>{DARK_CSS}</style>;
}

const MoonIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>;
const SunIcon  = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>;

// Topbar toggle (Admin and HR layouts)
export function NightToggle({ dark, onToggle }) {
  return (
    <button
      type="button"
      onClick={onToggle}
      title={dark ? "Switch to light mode" : "Switch to night mode"}
      aria-label={dark ? "Switch to light mode" : "Switch to night mode"}
      className="flex items-center gap-1.5 px-2.5 py-1.5 bg-white border border-black/[0.09] rounded-md text-xs text-slate-500 hover:text-slate-800 transition-colors cursor-pointer"
    >
      {dark ? <SunIcon /> : <MoonIcon />}
      <span className="hidden sm:inline">{dark ? "Light" : "Night"}</span>
    </button>
  );
}

// Wrapper for pages without a shared layout (Checker, Salesman,
// Maintenance, Driver). Applies night mode and adds a small toggle in the
// empty strip at the top right of their headers (they use pt-10).
export function NightScope({ children }) {
  const [dark, setDark] = useNightMode();
  return (
    <div className={dark ? "cp-dark" : ""}>
      <NightStyle />
      {children}
      <button
        type="button"
        onClick={() => setDark((d) => !d)}
        title={dark ? "Switch to light mode" : "Switch to night mode"}
        aria-label={dark ? "Switch to light mode" : "Switch to night mode"}
        className="fixed top-2 right-3 z-30 flex items-center gap-1 px-2 py-1 bg-white border border-black/[0.09] rounded-md text-[11px] text-slate-500 shadow-sm cursor-pointer"
      >
        {dark ? <SunIcon /> : <MoonIcon />}
        <span>{dark ? "Light" : "Night"}</span>
      </button>
    </div>
  );
}