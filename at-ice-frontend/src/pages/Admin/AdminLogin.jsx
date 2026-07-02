import { useState } from "react";
import { useNavigate } from "react-router-dom";
import bgImage from "../../assets/iceproduct.png";

// ─── Icons ───────────────────────────────────────────────────────────────────
const BoltIcon = () => (
  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
  </svg>
);
const MailIcon = () => (
  <svg width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
    <polyline points="22,6 12,13 2,6"/>
  </svg>
);
const LockIcon = () => (
  <svg width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
  </svg>
);
const EyeIcon = () => (
  <svg width="17" height="17" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
    <circle cx="12" cy="12" r="3" />
  </svg>
);
const EyeOffIcon = () => (
  <svg width="17" height="17" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94" />
    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19" />
    <line x1="1" y1="1" x2="23" y2="23" />
  </svg>
);
const ShieldIcon = () => (
  <svg width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
  </svg>
);

export default function AdminLogin() {
  const navigate = useNavigate();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleLogin(e) {
    e.preventDefault();
    setError("");
    if (!email.trim() || !password.trim()) {
      setError("Please enter your email and password.");
      return;
    }
    setLoading(true);
    try {
      const res = await fetch("/api/login", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email, password }),
      });
      const data = await res.json();
      if (!res.ok) {
        setError(data.message || "Invalid credentials. Please try again.");
        setLoading(false);
        return;
      }
      const userRole = data.role || data.user?.role;
        if (userRole !== "admin") {
        setError("Access denied. Admin credentials required.");
        setLoading(false);
        return;
      }
      localStorage.setItem("token", data.token);
      localStorage.setItem("role", userRole);
      window.location.href = "/dashboard";
    } catch {
      setError("Network error. Please check your connection.");
      setLoading(false);
    }
  }

  return (
    <div className="relative w-full min-h-screen flex items-center justify-center overflow-hidden">

      {/* ── Background ── */}
      <div
        className="absolute inset-0"
        style={{
          backgroundImage: `url(${bgImage})`,
          backgroundSize: "cover",
          backgroundPosition: "center",
        }}
      />
      <div className="absolute inset-0 bg-gradient-to-br from-slate-900/80 via-slate-900/60 to-cyan-900/40" />

      {/* ── Card ── */}
      <div className="relative z-10 w-full max-w-md mx-4">

        {/* Brand */}
        <div className="flex flex-col items-center mb-8">
          <div className="w-16 h-16 bg-white/10 border border-white/20 backdrop-blur-sm rounded-2xl flex items-center justify-center text-cyan-400 shadow-lg shadow-cyan-500/10 mb-4">
            <BoltIcon />
          </div>
          <div className="text-[26px] font-semibold text-white tracking-wide leading-none">Bella Erin</div>
          <div className="text-[11px] text-cyan-400/80 tracking-[0.2em] uppercase mt-1.5">AT-ICE ERP</div>
          <div className="flex items-center gap-1.5 mt-3 px-3.5 py-1.5 bg-cyan-500/[0.15] border border-cyan-500/30 rounded-full backdrop-blur-sm">
            <span className="text-cyan-400"><ShieldIcon /></span>
            <span className="text-[12px] font-medium text-cyan-300 tracking-wide">Administrator Portal</span>
          </div>
        </div>

        {/* Login Card */}
        <div className="bg-white rounded-2xl shadow-2xl shadow-black/30 overflow-hidden">

          {/* Card header */}
          <div className="px-8 pt-7 pb-5 border-b border-black/[0.06]">
            <h2 className="text-[18px] font-semibold text-slate-800 leading-tight">Sign in to your account</h2>
            <p className="text-[13px] text-slate-400 mt-1">Enter your admin credentials to continue.</p>
          </div>

          {/* Form */}
          <div className="px-8 py-6">
            <form onSubmit={handleLogin} className="flex flex-col gap-4">

              {/* Email */}
              <div className="flex flex-col gap-2">
                <label className="text-[11px] font-medium text-slate-500 tracking-widest uppercase">Email Address</label>
                <div className="flex items-center gap-3 px-4 py-3.5 border border-black/[0.12] rounded-xl bg-slate-50 focus-within:border-cyan-400 focus-within:bg-white focus-within:ring-2 focus-within:ring-cyan-500/10 transition-all">
                  <span className="text-slate-400 flex-shrink-0"><MailIcon /></span>
                  <input
                    type="email"
                    placeholder="admin@atice.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    autoComplete="email"
                    autoCapitalize="none"
                    className="flex-1 bg-transparent text-[15px] text-slate-800 placeholder-slate-400 outline-none min-w-0"
                  />
                </div>
              </div>

              {/* Password */}
              <div className="flex flex-col gap-2">
                <label className="text-[11px] font-medium text-slate-500 tracking-widest uppercase">Password</label>
                <div className="flex items-center gap-3 px-4 py-3.5 border border-black/[0.12] rounded-xl bg-slate-50 focus-within:border-cyan-400 focus-within:bg-white focus-within:ring-2 focus-within:ring-cyan-500/10 transition-all">
                  <span className="text-slate-400 flex-shrink-0"><LockIcon /></span>
                  <input
                    type={showPassword ? "text" : "password"}
                    placeholder="Enter your password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    autoComplete="current-password"
                    className="flex-1 bg-transparent text-[15px] text-slate-800 placeholder-slate-400 outline-none min-w-0"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((v) => !v)}
                    className="text-slate-400 hover:text-slate-600 transition-colors flex-shrink-0 p-1 -mr-1 cursor-pointer"
                    tabIndex={-1}
                  >
                    {showPassword ? <EyeOffIcon /> : <EyeIcon />}
                  </button>
                </div>
              </div>

              {/* Error */}
              {error && (
                <div className="text-[12px] text-red-500 bg-red-50 border border-red-100 rounded-xl px-4 py-3 text-center">
                  {error}
                </div>
              )}

              {/* Submit */}
              <button
                type="submit"
                disabled={loading}
                className="mt-1 w-full py-3.5 bg-cyan-500 hover:bg-cyan-600 active:bg-cyan-700 text-white text-[15px] font-semibold rounded-xl transition-colors duration-150 flex items-center justify-center gap-2.5 cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed shadow-lg shadow-cyan-500/25"
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

            <p className="text-center text-[11px] text-slate-400 mt-5">
              Having trouble?{" "}
              <span className="text-cyan-500 cursor-pointer hover:text-cyan-600 transition-colors">Contact IT support</span>
            </p>
          </div>
        </div>

        <p className="text-center text-[10px] text-white/30 mt-5 tracking-wide">
          © 2026 AT-ICE ERP · Administrator Module v1.0
        </p>
      </div>
    </div>
  );
}