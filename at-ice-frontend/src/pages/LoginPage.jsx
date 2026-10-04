import { useEffect, useState } from "react";
import { Navigate, useNavigate } from "react-router-dom";
import bgImage from "../assets/iceproduct.png";
import logo from "../assets/logo.png";
import { clearSession, postLoginPath, saveSession } from "../lib/auth";
import { takeSessionExpiredNotice } from "../lib/sessionExpiry";

const MailIcon = () => (
  <svg width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24">
    <rect x="2" y="4" width="20" height="16" rx="2" /><polyline points="2,4 12,13 22,4" />
  </svg>
);
const LockIcon = () => (
  <svg width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24">
    <rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" />
  </svg>
);
const EyeIcon = () => (
  <svg width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24">
    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" />
  </svg>
);
const EyeOffIcon = () => (
  <svg width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24">
    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
    <line x1="1" y1="1" x2="23" y2="23" />
  </svg>
);

// The one login page for every role. After login the user goes to their own
// dashboard, chosen by the role and sub_role the API returns.
export default function LoginPage() {
  const navigate = useNavigate();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  // "Your session expired..." after an API call came back 401 (see lib/sessionExpiry.js)
  const [notice, setNotice] = useState(() => takeSessionExpiredNotice());

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
    fetch("/api/me", { headers: { Accept: "application/json", Authorization: `Bearer ${token}` } })
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
      });
    return () => {
      cancelled = true;
    };
  }, []);

  async function handleLogin(e) {
    e.preventDefault();
    setError("");
    setNotice("");
    if (!email.trim() || !password) {
      setError("Please enter your email and password.");
      return;
    }
    setLoading(true);
    try {
      const res = await fetch("/api/login", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email: email.trim(), password }),
      });
      const data = await res.json().catch(() => null);
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
        setError("Your account has no role assigned. Contact HR.");
        return;
      }

      saveSession({ ...data, role });
      navigate(path, { replace: true });
    } catch {
      setError("Network error. Please check your connection.");
    } finally {
      setLoading(false);
    }
  }

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
          <div className="w-16 h-16 bg-white/10 border border-white/20 backdrop-blur-sm rounded-2xl flex items-center justify-center overflow-hidden shadow-lg">
            <img src={logo} alt="Bella Erin logo" className="w-full h-full object-contain p-2" />
          </div>
          <div className="text-[26px] font-semibold text-white tracking-wide leading-none mt-3">Bella Erin</div>
          <div className="text-[11px] text-cyan-400/80 tracking-[0.2em] uppercase mt-1.5">AT-ICE ERP</div>
        </div>

        <div className="bg-white rounded-2xl shadow-2xl shadow-black/30 overflow-hidden">
          <div className="px-5 sm:px-8 pt-6 sm:pt-7 pb-4 sm:pb-5 border-b border-black/[0.06]">
            <h1 className="text-[18px] font-semibold text-slate-800 leading-tight">Sign in to your account</h1>
            <p className="text-[13px] text-slate-400 mt-1">Use the email and password given to you.</p>
          </div>

          <div className="px-5 sm:px-8 py-5 sm:py-6">
            <form onSubmit={handleLogin} className="flex flex-col gap-4" noValidate>
              <div className="flex flex-col gap-2">
                <label htmlFor="login-email" className="text-[11px] font-medium text-slate-500 tracking-widest uppercase">
                  Email Address
                </label>
                <div className="flex items-center gap-3 px-4 min-h-[48px] border border-black/[0.12] rounded-xl bg-slate-50 focus-within:border-cyan-400 focus-within:bg-white focus-within:ring-2 focus-within:ring-cyan-500/10 transition-all">
                  <span className="text-slate-400 flex-shrink-0"><MailIcon /></span>
                  {/* 16px text stops iOS from zooming the page when the field is focused */}
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
                    className="flex-1 bg-transparent text-[16px] text-slate-800 placeholder-slate-400 outline-none min-w-0 py-3"
                  />
                </div>
              </div>

              <div className="flex flex-col gap-2">
                <label htmlFor="login-password" className="text-[11px] font-medium text-slate-500 tracking-widest uppercase">
                  Password
                </label>
                <div className="flex items-center gap-3 pl-4 pr-1 min-h-[48px] border border-black/[0.12] rounded-xl bg-slate-50 focus-within:border-cyan-400 focus-within:bg-white focus-within:ring-2 focus-within:ring-cyan-500/10 transition-all">
                  <span className="text-slate-400 flex-shrink-0"><LockIcon /></span>
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
                    className="flex-1 bg-transparent text-[16px] text-slate-800 placeholder-slate-400 outline-none min-w-0 py-3"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((v) => !v)}
                    aria-label={showPassword ? "Hide password" : "Show password"}
                    className="text-slate-400 hover:text-slate-600 transition-colors flex-shrink-0 w-11 h-11 flex items-center justify-center cursor-pointer"
                  >
                    {showPassword ? <EyeOffIcon /> : <EyeIcon />}
                  </button>
                </div>
              </div>

              {notice && !error && (
                <div role="status" className="text-[13px] text-amber-700 bg-amber-50 border border-amber-100 rounded-xl px-4 py-3 text-center">
                  {notice}
                </div>
              )}

              {error && (
                <div role="alert" className="text-[13px] text-red-500 bg-red-50 border border-red-100 rounded-xl px-4 py-3 text-center">
                  {error}
                </div>
              )}

              <button
                type="submit"
                disabled={loading}
                className="mt-1 w-full min-h-[48px] py-3 bg-cyan-500 hover:bg-cyan-600 active:bg-cyan-700 text-white text-[16px] font-semibold rounded-xl transition-colors duration-150 flex items-center justify-center gap-2.5 cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed shadow-lg shadow-cyan-500/25"
              >
                {loading ? (
                  <>
                    <span className="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                    Signing in…
                  </>
                ) : (
                  "Sign In"
                )}
              </button>
            </form>

            <p className="text-center text-[12px] text-slate-400 mt-5">
              Having trouble signing in? Contact the Admin or HR.
            </p>
          </div>
        </div>

        <p className="text-center text-[10px] text-white/30 mt-5 tracking-wide">© 2026 AT-ICE ERP</p>
      </div>
    </div>
  );
}
