import { useEffect } from "react";
import { ACTIVITY_KEY, INPUT_KEY, LOGIN_PATH } from "../lib/auth";
import { signOutForIdle } from "../lib/sessionExpiry";

// Keeps a sign-in alive while its tab is open, and ends it when nobody is
// there. Mounted once in App.jsx. A sign-in has an idle limit only when the
// login sent idle_minutes (30 for Admin, HR and Customer); no value means no
// limit and this does nothing, so the phones of the employees are untouched.
//
//   - Tab closed, computer off or asleep: the pings stop and the server ends
//     the sign-in after idle_minutes (middleware idle). A tab that wakes up
//     works out how long it was away from the clock.
//   - Tab open: a small ping every minute keeps the sign-in alive even if
//     nobody touches the page (a meeting, a lunch break).
//   - Tab open but untouched for a whole working day (no mouse, key, scroll or
//     touch for OPEN_TAB_LIMIT), the computer was probably left on: sign out.
//   - Times live in localStorage, so every tab shares them, and signing out in
//     one tab sends the others to the login page.

const PING_EVERY = 60_000;
const GRACE = 60_000; // same extra minute the server gives
const OPEN_TAB_LIMIT = 8 * 60 * 60_000;
const INPUT_EVENTS = ["pointerdown", "pointermove", "keydown", "wheel", "scroll", "touchstart"];
const WRITE_EVERY = 30_000;

const now = () => Date.now();
const limitMs = () => {
  if (!localStorage.getItem("token")) return 0;
  return Math.max(0, Number(localStorage.getItem("idle_minutes")) || 0) * 60_000;
};
const read = (key) => Number(localStorage.getItem(key)) || 0;
const mark = (key) => {
  try { localStorage.setItem(key, String(now())); } catch { /* storage full or blocked */ }
};

export default function IdleGuard() {
  useEffect(() => {
    let sending = false;
    let listening = false;
    let lastWrite = 0;

    const onInput = () => {
      if (now() - lastWrite < WRITE_EVERY) return;
      lastWrite = now();
      mark(INPUT_KEY);
    };
    // Input listeners exist only while this sign-in has a limit
    const listen = (on) => {
      if (on === listening) return;
      listening = on;
      INPUT_EVENTS.forEach((name) => {
        if (on) document.addEventListener(name, onInput, { passive: true, capture: true });
        else document.removeEventListener(name, onInput, { capture: true });
      });
    };

    const ping = () => {
      if (sending) return;
      sending = true;
      fetch("/api/session/ping", {
        method: "POST",
        headers: { Accept: "application/json", Authorization: `Bearer ${localStorage.getItem("token")}` },
      })
        .then((res) => { if (res.ok) mark(ACTIVITY_KEY); })
        .catch(() => {})
        .finally(() => { sending = false; });
    };

    const tick = () => {
      const limit = limitMs();
      listen(limit > 0);
      if (!limit) return;
      // First time we see this sign-in (a page opened before this rule): start counting now
      if (!read(ACTIVITY_KEY)) mark(ACTIVITY_KEY);
      if (!read(INPUT_KEY)) mark(INPUT_KEY);

      if (now() - read(ACTIVITY_KEY) >= limit + GRACE || now() - read(INPUT_KEY) >= OPEN_TAB_LIMIT) {
        signOutForIdle();
      } else if (now() - read(ACTIVITY_KEY) >= PING_EVERY) {
        ping();
      }
    };

    const onWake = () => { if (!document.hidden) tick(); };
    const onStorage = (e) => {
      // signed out in another tab
      if (e.key === "token" && !e.newValue && window.location.pathname !== LOGIN_PATH) {
        window.location.assign(LOGIN_PATH);
      }
    };

    document.addEventListener("visibilitychange", onWake);
    window.addEventListener("focus", onWake);
    window.addEventListener("online", onWake);
    window.addEventListener("storage", onStorage);
    const timer = setInterval(tick, 5000);
    tick();

    return () => {
      listen(false);
      document.removeEventListener("visibilitychange", onWake);
      window.removeEventListener("focus", onWake);
      window.removeEventListener("online", onWake);
      window.removeEventListener("storage", onStorage);
      clearInterval(timer);
    };
  }, []);

  return null;
}
