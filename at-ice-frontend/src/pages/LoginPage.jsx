import { useEffect, useLayoutEffect, useRef, useState } from "react";
import { Link, Navigate, useNavigate } from "react-router-dom";
import bgImage from "../assets/iceproduct.png";
import logo from "../assets/logo.png";
import { PinEntry } from "../components/KioskScreens";
import { RevealButton } from "../components/PasswordInput";
import { useReveal } from "../lib/useReveal";
import { clearSession, postLoginPath, saveSession } from "../lib/auth";
import { isNativeApp } from "../lib/native";
import { startPush, appHeaders } from "../lib/push";
import { isBlockedDevice } from "../lib/kiosk";
import { takeSessionExpiredNotice } from "../lib/sessionExpiry";
import { clock, lockedEmail, useLoginLock } from "../lib/loginLock";
import { playSplash } from "../lib/splash";
import { Icon } from "../components/icons";

// The AT-ICE app icon artwork shown in the download panel (public/download-poster.webp)
const POSTER = "/download-poster.webp";

// The Android app (APK) is served by the backend (same place as the API, so it works the same
// on localhost and live, with the right file type). Set VITE_APK_URL to host it elsewhere.
const APK_URL = import.meta.env.VITE_APK_URL || "/api/app-download";
const LOGIN_TIMEOUT_MS = 15000;

const MailIcon = () => (
  <Icon name="mail" size={18} strokeWidth={1.8} />
);
const LockIcon = () => (
  <Icon name="lockWide" size={18} strokeWidth={1.8} />
);

const fieldBox =
  "neu-inset flex items-center gap-3 pl-4 min-h-[48px] rounded-xl focus-within:shadow-[inset_4px_4px_8px_var(--neu-lo),inset_-4px_-4px_8px_var(--neu-hi)] transition-shadow";
// 16px text stops iOS from zooming the page when the field is focused
const fieldInput = "flex-1 bg-transparent text-[16px] text-slate-800 placeholder:text-[var(--neu-faint)] outline-none min-w-0 py-3 pr-4";

// Sliding card geometry (px): the least card height (it grows with the
// sign-in form, e.g. when an error shows, so the peeking Attendance Station
// panel never covers the form), how far the panel stops from the top when
// open, and how much of it peeks when closed.
const CARD_H = 560;
const DOWNLOAD_H = 740; // least card height on a phone or tablet (taller download panel)
const KIOSK_TOP = 64;
const PEEK = 72;

// The one login page for every role. After login the user goes to their own
// dashboard, chosen by the role and sub_role the API returns.
export default function LoginPage() {
  const navigate = useNavigate();
  // a lockout in progress puts its email back after a refresh (lib/loginLock.js)
  const [email, setEmail] = useState(lockedEmail);
  const [password, setPassword] = useState("");
  // the eye button shows or hides the password
  const [showPassword, revealProps] = useReveal();
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const lock = useLoginLock(email);
  // "Your session expired..." after an API call came back 401 (see lib/sessionExpiry.js)
  const [notice, setNotice] = useState(() => takeSessionExpiredNotice());

  // Sliding card: "login" shows the sign-in form, "kiosk" the Attendance Station PIN.
  const [mode, setMode] = useState("login");
  const kiosk = mode === "kiosk";
  const [kioskBlocked, setKioskBlocked] = useState(false);

  // Phones and tablets get "Download AT-ICE App" in place of the Attendance Station.
  // Inside the app itself there is nothing to download, so the panel is left out.
  const [phoneView, setPhoneView] = useState(() => isBlockedDevice());
  useEffect(() => {
    const onResize = () => setPhoneView(isBlockedDevice());
    window.addEventListener("resize", onResize);
    return () => window.removeEventListener("resize", onResize);
  }, []);
  const native = isNativeApp();

  const [dlBusy, setDlBusy] = useState(false);
  const [dlError, setDlError] = useState("");
  // Checks the file really is there first: a missing file would otherwise download the web page.
  async function downloadApp() {
    if (dlBusy) return;
    setDlError("");
    setDlBusy(true);
    try {
      // the backend says whether an app file is published (it also serves the file)
      const res = await fetch("/api/app-version", { headers: { Accept: "application/json" } });
      const info = res.ok ? await res.json().catch(() => null) : null;
      if (!info || !(Number(info.build) > 0)) {
        setDlError("The app file is not available yet. Please try again later or ask the Admin.");
        return;
      }
      const a = document.createElement("a");
      a.href = APK_URL;
      a.download = "AT-ICE.apk";
      document.body.appendChild(a);
      a.click();
      a.remove();
    } catch {
      setDlError("Network error. Please check your connection.");
    } finally {
      setDlBusy(false);
    }
  }

  // Bumped on every open so the PIN boxes start empty and take the focus.
  const [pinRun, setPinRun] = useState(0);
  const showKiosk = () => {
    if (kiosk) return;
    setKioskBlocked(isBlockedDevice());
    setDlError("");
    setPinRun((n) => n + 1);
    setMode("kiosk");
    if (isBlockedDevice()) followCardDown();
  };
  // The download panel is taller than the screen: while the card grows, scroll
  // along so the whole panel comes into view.
  const cardRef = useRef(null);
  function followCardDown() {
    const start = performance.now();
    const step = (now) => {
      const el = cardRef.current;
      if (!el) return;
      const over = el.getBoundingClientRect().bottom + 24 - window.innerHeight;
      if (over > 0) window.scrollBy(0, Math.min(over, 40));
      if (now - start < 900) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  }
  const showLogin = () => setMode("login");

  useEffect(() => {
    if (!kiosk) return;
    const onKey = (e) => e.key === "Escape" && setMode("login");
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [kiosk]);

  // A user who is already signed in goes straight to their dashboard. The saved
  // token is checked first, so an expired one does not leave them stuck.
  const [existing, setExisting] = useState(null); // null = checking, false = none, string = path
  useEffect(() => {
    const token = localStorage.getItem("token");
    if (!token) {
      setExisting(false);
      return;
    }
    let cancelled = false;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), LOGIN_TIMEOUT_MS);
    fetch("/api/me", { signal: controller.signal, headers: { Accept: "application/json", Authorization: `Bearer ${token}` } })
      .then((res) => {
        if (cancelled) return;
        const path = res.ok
          ? postLoginPath(
              localStorage.getItem("role"),
              localStorage.getItem("sub_role"),
              localStorage.getItem("must_change_password") === "1"
            )
          : null;
        if (!path) clearSession();
        if (!res.ok) {
          // The saved token was rejected: tell the user why they are seeing the form.
          const expired = takeSessionExpiredNotice();
          if (expired) setNotice(expired);
        }
        setExisting(path || false);
      })
      .catch(() => {
        if (!cancelled) setExisting(false);
      })
      .finally(() => clearTimeout(timer));
    return () => {
      cancelled = true;
      clearTimeout(timer);
      controller.abort();
    };
  }, []);

  async function handleLogin(e) {
    e.preventDefault();
    setError("");
    setNotice("");
    if (loading || lock.locked) return; // the server refuses it anyway; this just skips the call
    if (!email.trim() || !password) {
      setError("Please enter your email and password.");
      return;
    }
    setLoading(true);
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), LOGIN_TIMEOUT_MS);
    try {
      const res = await fetch("/api/login", {
        signal: controller.signal,
        method: "POST",
        // from the Android app the four mobile roles stay signed in (no session time limit)
        headers: { "Content-Type": "application/json", Accept: "application/json", ...appHeaders() },
        body: JSON.stringify({ email: email.trim(), password }),
      });
      const data = await res.json().catch((err) => {
        if (controller.signal.aborted) throw err;
        return null;
      });
      if (res.status === 429 && data?.retry_after_seconds) {
        lock.start(Number(data.retry_after_seconds));
        return;
      }
      if (!res.ok) {
        setError(data?.message || "Invalid credentials. Please try again.");
        return;
      }

      const role = data.role || data.user?.role;
      const path = postLoginPath(role, data.sub_role, data.must_change_password);
      if (!path) {
        // An employee account without a role cannot be sent anywhere. Revoke the token just issued.
        fetch("/api/logout", {
          method: "POST",
          headers: { Accept: "application/json", Authorization: `Bearer ${data.token}` },
        }).catch(() => {});
        setError(isNativeApp() && role ? "This app is for Driver, Checker, Salesman and Maintenance only. Please use the website." : "Your account has no role assigned. Contact HR.");
        return;
      }

      saveSession({ ...data, role });
      startPush(data.token);
      playSplash();
      navigate(path, { replace: true });
    } catch {
      setError(controller.signal.aborted
        ? "Cannot reach the server. Check the server connection and try again. For USB testing, keep the USB connection active."
        : "Network error. Please check your connection.");
    } finally {
      clearTimeout(timer);
      setLoading(false);
    }
  }

  // The card grows with the sign-in form (an error or notice adds a line)
  const formLayer = useRef(null);
  const [cardH, setCardH] = useState(CARD_H);
  useLayoutEffect(() => {
    const fit = () => {
      const el = formLayer.current;
      // the download panel is taller than the PIN panel, so the card needs more room on phones
      // closed it keeps the normal length; it only grows while the panel is open
      const least = phoneView && !native && kiosk ? DOWNLOAD_H : CARD_H;
      if (el) setCardH(Math.max(least, el.scrollHeight + PEEK + 12));
    };
    fit();
    window.addEventListener("resize", fit);
    return () => window.removeEventListener("resize", fit);
  }, [error, notice, existing, phoneView, native, kiosk]);

  if (typeof existing === "string") return <Navigate to={existing} replace />;
  if (existing === null) {
    return <div className="min-h-[100dvh] w-full bg-slate-900" aria-busy="true" />;
  }

  return (
    <div className="relative w-full min-h-[100dvh] flex items-start sm:items-center justify-center overflow-y-auto">
      {/* Background */}
      <div
        className="fixed inset-0"
        style={{ backgroundImage: `url(${bgImage})`, backgroundSize: "cover", backgroundPosition: "center" }}
      />
      <div className="fixed inset-0 bg-gradient-to-br from-slate-900/80 via-slate-900/60 to-cyan-900/40" />

      <div className="relative z-10 w-full max-w-md px-4 py-8 sm:py-10">
        {/* Brand */}
        <div className="flex flex-col items-center mb-6 sm:mb-8 text-center">
          <div className="brand-logo w-20 h-20 bg-white/10 border border-white/20 backdrop-blur-sm rounded-2xl flex items-center justify-center overflow-hidden shadow-lg">
            <img src={logo} alt="AT-ICE logo" className="w-full h-full object-contain p-2" />
          </div>
          <div className="text-[26px] font-semibold text-white tracking-wide leading-none mt-3">Bella Erin</div>
          <div className="text-[11px] text-cyan-400/80 tracking-[0.2em] uppercase mt-1.5">AT-ICE ERP</div>
        </div>

        {/* Sliding card (idea from Nazia-99/Sliding-Login-Form). The sign-in form
            is the base layer; the Attendance Station panel lies over it and
            peeks from the bottom. Clicking its title slides it up over the form
            and shows the HR PIN; clicking "Login" (or Esc, or Cancel) slides it
            back down. Only transform and opacity animate. */}
        <div ref={cardRef} className="relative rounded-[28px] overflow-clip shadow-2xl shadow-black/40 bg-[var(--neu-bg)] transition-[height] duration-[800ms] ease-in-out"
          style={{ height: cardH }}>
          {/* Base layer: sign in */}
          <div ref={formLayer} className="absolute inset-x-0 top-0 flex flex-col px-5 sm:px-8">
            <button
              type="button"
              onClick={showLogin}
              tabIndex={kiosk ? 0 : -1}
              aria-label={kiosk ? "Back to Login" : undefined}
              className={`slide-title self-center mt-6 text-[34px] font-bold text-[var(--neu-accent)] tracking-wide cursor-pointer transition-transform duration-500 ease-in-out ${kiosk ? "scale-[0.6] hover:text-cyan-600" : "scale-100 cursor-default"}`}
            >
              Login
            </button>

            <div
              inert={kiosk}
              className={`transition-opacity duration-500 ${kiosk ? "opacity-0" : "opacity-100 delay-200"}`}
            >
              <div className="text-center mt-1 mb-5">
                <h1 className="text-[16px] font-semibold text-slate-800 leading-tight">Sign in to your account</h1>
                <p className="text-[13px] text-[var(--neu-muted)] mt-1">Use the email and password given to you.</p>
              </div>

            <form onSubmit={handleLogin} className="flex flex-col gap-4" noValidate>
              <div className="flex flex-col gap-2">
                <label htmlFor="login-email" className="text-[11px] font-semibold text-[var(--neu-muted)] tracking-widest uppercase">
                  Email Address
                </label>
                <div className={fieldBox}>
                  <span className="text-[var(--neu-faint)] flex-shrink-0"><MailIcon /></span>
                  <input
                    id="login-email"
                    type="email"
                    inputMode="email"
                    enterKeyHint="next"
                    placeholder="name@example.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    autoComplete="username"
                    autoCapitalize="none"
                    autoCorrect="off"
                    spellCheck={false}
                    className={fieldInput}
                  />
                </div>
              </div>

              <div className="flex flex-col gap-2">
                <label htmlFor="login-password" className="text-[11px] font-semibold text-[var(--neu-muted)] tracking-widest uppercase">
                  Password
                </label>
                <div className={`${fieldBox} pr-1`}>
                  <span className="text-[var(--neu-faint)] flex-shrink-0"><LockIcon /></span>
                  <input
                    id="login-password"
                    type={showPassword ? "text" : "password"}
                    enterKeyHint="go"
                    placeholder="Enter your password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    autoComplete="current-password"
                    autoCapitalize="none"
                    autoCorrect="off"
                    spellCheck={false}
                    className={fieldInput}
                  />
                  <RevealButton revealed={showPassword} buttonProps={revealProps} className="w-11 h-11 !text-[var(--neu-faint)] hover:!text-slate-700" />
                </div>
                <Link to="/forgot-password" className="self-end py-2.5 -my-2.5 text-[13px] font-medium text-[var(--neu-accent)] hover:underline">
                  Forgot password?
                </Link>
              </div>

              {notice && !error && (
                <div role="status" className="text-[13px] text-amber-800 bg-amber-100/70 border border-amber-300/60 rounded-xl px-4 py-3 text-center">
                  {notice}
                </div>
              )}

              {lock.locked && (
                <div role="alert" className="text-[13px] text-amber-900 bg-amber-100/70 border border-amber-300/60 rounded-xl px-4 py-3 text-center">
                  <div className="font-semibold">Sign-in is temporarily locked</div>
                  <div className="mt-0.5">Too many wrong passwords. Try again in <span className="font-mono tabular-nums font-semibold">{clock(lock.seconds)}</span>.</div>
                </div>
              )}

              {error && !lock.locked && (
                <div role="alert" className="text-[13px] text-red-700 bg-red-100/70 border border-red-300/60 rounded-xl px-4 py-3 text-center">
                  {error}
                </div>
              )}

              <button
                type="submit"
                disabled={loading || lock.locked}
                className="mt-1 w-full min-h-[48px] py-3 bg-cyan-500 hover:bg-cyan-400 active:bg-cyan-600 text-slate-900 text-[16px] font-semibold rounded-xl transition-[background-color,box-shadow] duration-150 flex items-center justify-center gap-2.5 cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed shadow-[5px_5px_12px_var(--neu-lo),-5px_-5px_12px_var(--neu-hi)] active:shadow-[inset_3px_3px_6px_rgba(8,51,68,0.45),inset_-3px_-3px_6px_rgba(255,255,255,0.2)]"
              >
                {loading ? (
                  <>
                    <span className="w-4 h-4 border-2 border-slate-900/30 border-t-slate-900 rounded-full animate-spin" />
                    Signing in…
                  </>
                ) : lock.locked ? (
                  `Locked ${clock(lock.seconds)}`
                ) : (
                  "Sign In"
                )}
              </button>
            </form>

            <p className="text-center text-[12px] text-[var(--neu-muted)] mt-5">
              Having trouble signing in? Contact the Admin or HR.
            </p>
            </div>
          </div>

          {/* Sliding layer: Attendance Station on a computer, Download AT-ICE App on a phone or tablet */}
          {!native && (
          <div
            className="neu-dark absolute inset-x-0 top-0 bg-[var(--neu-bg)] shadow-[0_-10px_24px_rgba(163,177,198,0.55)] transition-[transform,height] duration-[800ms] ease-in-out"
            style={{
              height: cardH - KIOSK_TOP,
              // Curved top only, so no light corners show at the bottom of the card
              borderRadius: "60% 60% 0 0 / 56px 56px 0 0",
              transform: `translateY(${kiosk ? KIOSK_TOP : cardH - PEEK}px)`,
            }}
          >
            <button
              type="button"
              onClick={showKiosk}
              tabIndex={kiosk ? -1 : 0}
              className={`slide-title block mx-auto mt-3.5 whitespace-nowrap outline-none ${phoneView ? "text-[26px]" : "text-[32px]"} font-bold text-white tracking-wide transition-transform duration-500 ease-in-out ${kiosk ? "scale-100 cursor-default" : "scale-[0.6] cursor-pointer hover:text-cyan-200"}`}
            >
              {phoneView ? (
                <span className="inline-flex items-center">
                  {/* The icon squeezes in from both sides and vanishes while the panel rises */}
                  <span
                    aria-hidden="true"
                    className={`inline-flex origin-center overflow-hidden transition-[transform,width,margin,opacity] duration-[800ms] ease-in-out ${kiosk ? "w-0 mr-0 scale-x-0 opacity-0" : "w-8 mr-2 scale-x-100 opacity-100"}`}
                  >
                    <Icon name="download" size={30} strokeWidth={2} />
                  </span>
                  Download AT-ICE App
                </span>
              ) : (
                "Attendance Station"
              )}
            </button>

            <div
              inert={!kiosk}
              className={`px-6 sm:px-10 pt-3 pb-5 text-center transition-opacity duration-500 ${kiosk ? "opacity-100 delay-300" : "opacity-0"}`}
            >
              {phoneView ? (
                <>
                  <img
                    src={POSTER}
                    alt="AT-ICE"
                    className="mx-auto w-56 h-auto -mt-2"
                    // the artwork's edges fade into the dark panel, as in the design
                    style={{ WebkitMaskImage: "radial-gradient(ellipse 50% 50% at 50% 50%, #000 62%, transparent 100%)", maskImage: "radial-gradient(ellipse 50% 50% at 50% 50%, #000 62%, transparent 100%)" }}
                  />
                  <h2 className="mt-1 text-[22px] font-semibold text-white">AT-ICE ERP</h2>
                  <p className="mt-2 text-[14px] leading-relaxed text-slate-300">
                    The AT-ICE mobile app gives Bella Erin field staff quick and secure access to their daily work, anytime, anywhere.
                  </p>
                  {dlError && (
                    <div role="alert" className="mt-3 text-[13px] text-red-300">{dlError}</div>
                  )}
                  <button
                    type="button"
                    onClick={downloadApp}
                    disabled={dlBusy}
                    className="neu-raised-sm mt-4 w-full min-h-[52px] rounded-xl text-white hover:text-cyan-200 active:shadow-[var(--neu-inset-sm)] text-[17px] font-semibold cursor-pointer inline-flex items-center justify-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed"
                  >
                    <Icon name="download" size={22} strokeWidth={2} />
                    {dlBusy ? "Checking…" : "Download AT-ICE ERP"}
                  </button>
                  <div className="neu-inset-sm mt-4 rounded-2xl p-4 text-left flex flex-col gap-4">
                    <div className="flex gap-3">
                      <span className="text-slate-200 flex-shrink-0 mt-0.5"><Icon name="smartphone" size={30} strokeWidth={1.6} /></span>
                      <div>
                        <div className="text-[14px] font-semibold text-white">What's Inside?</div>
                        <p className="text-[13px] leading-snug text-slate-300">Deliveries and GPS routes, store checking, sales, and maintenance tasks, with the same account and data as the website.</p>
                      </div>
                    </div>
                    <div className="flex gap-3">
                      <span className="text-slate-200 flex-shrink-0 mt-0.5"><Icon name="users" size={30} strokeWidth={1.6} /></span>
                      <div>
                        <div className="text-[14px] font-semibold text-white">Who Can Use It?</div>
                        <p className="text-[13px] leading-snug text-slate-300">Drivers, Checkers, Salesmen and Maintenance staff only. Admin, HR and Customer accounts are restricted from the app.</p>
                      </div>
                    </div>
                  </div>
                </>
              ) : (
              <>
              <div className={`neu-raised-sm mx-auto w-14 h-14 rounded-2xl flex items-center justify-center ${kioskBlocked ? "text-amber-300" : "text-cyan-300"}`}>
                <LockIcon />
              </div>
              {kioskBlocked ? (
                <>
                  <h2 className="mt-4 text-[19px] font-semibold text-white">Access Restricted</h2>
                  <p className="mt-2 text-[13px] leading-relaxed text-[var(--neu-muted)]">
                    The Attendance Station can only be opened on a dedicated desktop terminal or kiosk station.
                  </p>
                </>
              ) : (
                <>
                  <h2 className="mt-4 text-[19px] font-semibold text-white">HR Authorization Required</h2>
                  <p className="mt-2 mb-6 text-[13px] leading-relaxed text-[var(--neu-muted)]">
                    Enter the 4-digit HR security PIN to unlock and launch the Attendance Station.
                  </p>
                  <PinEntry key={pinRun} onUnlocked={() => navigate("/attendance-station")} />
                </>
              )}
              <button
                type="button"
                onClick={showLogin}
                className="neu-raised-sm mt-5 w-full min-h-[44px] rounded-xl text-slate-200 hover:text-white active:shadow-[var(--neu-inset-sm)] text-[14px] font-medium cursor-pointer"
              >
                Cancel
              </button>
              </>
              )}
            </div>
          </div>
          )}
        </div>

        <p className="text-center text-[10px] text-white/30 mt-5 tracking-wide">© 2026 AT-ICE ERP</p>
      </div>
    </div>
  );
}
