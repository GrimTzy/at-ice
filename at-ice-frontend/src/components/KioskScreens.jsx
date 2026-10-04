import { useEffect, useState } from "react";
import { setKioskToken } from "../lib/kiosk";

// Screens of the Attendance Station gate (see KioskGate.jsx).
// Dark ice theme: slate background, cyan accent borders, warning icon badges.

const WarningIcon = () => (
  <svg width="28" height="28" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
    <line x1="12" y1="9" x2="12" y2="13" />
    <line x1="12" y1="17" x2="12.01" y2="17" />
  </svg>
);

const CameraOffIcon = () => (
  <svg width="28" height="28" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <line x1="1" y1="1" x2="23" y2="23" />
    <path d="M21 21H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h3m3-3h6l2 3h4a2 2 0 0 1 2 2v9.34m-7.72-2.06a4 4 0 1 1-5.56-5.56" />
  </svg>
);

const LockIcon = () => (
  <svg width="28" height="28" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <rect x="3" y="11" width="18" height="11" rx="2" />
    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
  </svg>
);

const BADGE_TONES = {
  warning: "bg-amber-500/10 border-amber-400/40 text-amber-300",
  danger: "bg-red-500/10 border-red-400/40 text-red-300",
  info: "bg-cyan-500/10 border-cyan-400/40 text-cyan-300",
};

function KioskShell({ tone = "warning", icon, title, children }) {
  return (
    <div className="min-h-[100dvh] w-full bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 flex items-center justify-center p-4">
      <div className="w-full max-w-md rounded-2xl bg-slate-800/90 border border-cyan-500/30 shadow-2xl shadow-black/50 p-7 sm:p-8 text-center">
        <div className={`mx-auto w-16 h-16 rounded-full border flex items-center justify-center ${BADGE_TONES[tone]}`}>
          {icon}
        </div>
        <h1 className="mt-5 text-[22px] font-semibold text-white leading-tight">{title}</h1>
        {children}
      </div>
    </div>
  );
}

const primaryBtn =
  "w-full min-h-[46px] px-4 py-2.5 rounded-xl bg-cyan-500 hover:bg-cyan-400 active:bg-cyan-600 text-slate-900 text-[14px] font-semibold transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed";
const ghostBtn =
  "w-full min-h-[44px] px-4 py-2 rounded-xl border border-cyan-500/30 text-cyan-200 hover:bg-cyan-500/10 text-[13px] font-medium transition-colors cursor-pointer";

export function KioskLoading() {
  return (
    <div className="min-h-[100dvh] w-full bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 flex items-center justify-center" aria-busy="true">
      <span className="w-8 h-8 border-2 border-cyan-500/30 border-t-cyan-400 rounded-full animate-spin" />
    </div>
  );
}

export function AccessRestrictedScreen({ onReturn }) {
  return (
    <KioskShell tone="warning" icon={<WarningIcon />} title="Access Restricted">
      <p className="mt-3 text-[14px] leading-relaxed text-slate-300">
        The Attendance Station can only be accessed using a dedicated desktop terminal or kiosk station.
      </p>
      <button type="button" onClick={onReturn} className={`${primaryBtn} mt-6`}>
        Return to System Login
      </button>
    </KioskShell>
  );
}

// status "none": no camera found. status "error": the check itself failed.
export function NoCameraScreen({ status, message, onRetry, onReturn }) {
  const failed = status === "error";
  return (
    <KioskShell tone="danger" icon={<CameraOffIcon />} title={failed ? "Hardware Check Failed" : "No Camera Detected"}>
      <p className="mt-3 text-[14px] leading-relaxed text-slate-300">
        {failed
          ? message || "The camera check could not be completed."
          : "Facial recognition requires an active webcam connected to this terminal."}
      </p>
      <button type="button" onClick={onRetry} className={`${primaryBtn} mt-6`}>
        Retry Hardware Check
      </button>
      <button type="button" onClick={onReturn} className={`${ghostBtn} mt-3`}>
        Return to System Login
      </button>
    </KioskShell>
  );
}

// Shown when the server cannot be reached while the gate checks the saved kiosk token.
export function KioskOfflineScreen({ onRetry, onReturn }) {
  return (
    <KioskShell tone="warning" icon={<WarningIcon />} title="Cannot Reach the Server">
      <p className="mt-3 text-[14px] leading-relaxed text-slate-300">
        Check the network connection of this terminal, then try again.
      </p>
      <button type="button" onClick={onRetry} className={`${primaryBtn} mt-6`}>
        Try Again
      </button>
      <button type="button" onClick={onReturn} className={`${ghostBtn} mt-3`}>
        Return to System Login
      </button>
    </KioskShell>
  );
}

const formatClock = (seconds) => {
  const m = Math.floor(seconds / 60);
  const s = String(seconds % 60).padStart(2, "0");
  return `${m}:${s}`;
};

// Asks for the 4-digit HR security PIN. The PIN is only ever checked by the backend.
export function PinModal({ onUnlocked, onCancel }) {
  const [pin, setPin] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [lockedUntil, setLockedUntil] = useState(0);
  const [now, setNow] = useState(() => Date.now());

  // Counts down a lockout.
  useEffect(() => {
    if (!lockedUntil) return;
    const id = setInterval(() => {
      const current = Date.now();
      setNow(current);
      if (current >= lockedUntil) {
        setLockedUntil(0);
        setError("");
      }
    }, 1000);
    return () => clearInterval(id);
  }, [lockedUntil]);

  const secondsLeft = lockedUntil ? Math.max(0, Math.ceil((lockedUntil - now) / 1000)) : 0;
  const lockedOut = secondsLeft > 0;

  async function handleSubmit(e) {
    e.preventDefault();
    if (loading || lockedOut || !/^\d{4}$/.test(pin)) return;
    setLoading(true);
    setError("");
    try {
      const res = await fetch("/api/kiosk/unlock", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ pin }),
      });
      const data = await res.json().catch(() => null);

      if (res.ok && data?.token) {
        setKioskToken(data.token);
        onUnlocked();
        return;
      }
      if (res.status === 429) {
        setNow(Date.now());
        setLockedUntil(Date.now() + (data?.retry_after_seconds || 900) * 1000);
        setError(data?.message || "Too many wrong PINs. Try again later.");
      } else if (res.status === 422 && typeof data?.attempts_remaining === "number") {
        setError(`Wrong PIN. ${data.attempts_remaining} attempt${data.attempts_remaining === 1 ? "" : "s"} left before a lockout.`);
      } else {
        setError(data?.message || "The PIN could not be checked. Please try again.");
      }
      setPin("");
    } catch {
      setError("Network error. Check the connection of this terminal and try again.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <KioskShell tone="info" icon={<LockIcon />} title="HR Authorization Required">
      <p className="mt-3 text-[14px] leading-relaxed text-slate-300">
        Enter the 4-digit HR security PIN to launch the Attendance Station.
      </p>

      <form onSubmit={handleSubmit} className="mt-6 flex flex-col gap-3" noValidate>
        <label htmlFor="kiosk-pin" className="sr-only">HR security PIN</label>
        <input
          id="kiosk-pin"
          type="password"
          inputMode="numeric"
          autoComplete="off"
          autoFocus
          maxLength={4}
          value={pin}
          disabled={loading || lockedOut}
          onChange={(e) => setPin(e.target.value.replace(/\D/g, "").slice(0, 4))}
          placeholder="----"
          className="w-full min-h-[56px] text-center text-[28px] tracking-[0.6em] pl-[0.6em] rounded-xl bg-slate-900 border border-cyan-500/40 text-white placeholder-slate-600 outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-500/20 disabled:opacity-50"
        />

        {error && (
          <div role="alert" className="text-[13px] text-red-300 bg-red-500/10 border border-red-400/30 rounded-xl px-4 py-3">
            {error}
            {lockedOut && <span className="block mt-1 font-mono text-red-200">{formatClock(secondsLeft)}</span>}
          </div>
        )}

        <button type="submit" disabled={loading || lockedOut || pin.length !== 4} className={primaryBtn}>
          {loading ? "Checking…" : "Unlock Attendance Station"}
        </button>
        <button type="button" onClick={onCancel} className={ghostBtn}>
          Cancel
        </button>
      </form>
    </KioskShell>
  );
}
