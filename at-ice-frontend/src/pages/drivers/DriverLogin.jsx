import { useState } from "react";
import { useNavigate } from "react-router-dom";
import bgImage from "../../assets/iceproduct.png";

// Icons 
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
const TruckIcon = () => (
  <svg width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <rect x="1" y="3" width="15" height="13" />
    <polygon points="16 8 20 8 23 11 23 16 16 16 16 8" />
    <circle cx="5.5" cy="18.5" r="2.5" />
    <circle cx="18.5" cy="18.5" r="2.5" />
  </svg>
);

export default function DriverLogin() {
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
      localStorage.setItem("token", data.token);
      localStorage.setItem("role", data.user?.role || "driver");
      navigate("/driver/deliveries");
    } catch {
      setError("Network error. Please check your connection.");
      setLoading(false);
    }
  }

  return (
    <div className="relative w-full min-h-screen flex flex-col overflow-hidden">
      {/* Background */}
      <div
        className="absolute inset-0"
        style={{
          backgroundImage: `url(${bgImage})`,
          backgroundSize: "cover",
          backgroundPosition: "center top",
        }}
      />
      <div className="absolute inset-0 bg-gradient-to-b from-white/10 via-white/40 to-white" />

      <div className="relative z-10 flex-shrink-0 h-12" />

      {/* Brand */}
      <div className="relative z-10 flex flex-col items-center pt-6 pb-2 px-6">
        <div className="w-14 h-14 bg-white/90 border border-cyan-500/25 rounded-2xl flex items-center justify-center overflow-hidden shadow-lg shadow-cyan-500/10 mb-3">
          <img src={logo} alt="Bella Erin logo" className="w-full h-full object-contain p-1.5" />
        </div>
        <div className="text-[22px] font-semibold text-slate-800 tracking-wide leading-none">Bella Erin</div>
        <div className="text-[10px] text-slate-500 tracking-[0.18em] uppercase mt-1">AT-ICE ERP</div>
        <div className="flex items-center gap-1.5 mt-3 px-3.5 py-1.5 bg-cyan-500/[0.1] border border-cyan-500/25 rounded-full">
          <span className="text-cyan-500"><TruckIcon /></span>
          <span className="text-[12px] font-medium text-cyan-600 tracking-wide">Driver Module</span>
        </div>
      </div>

      <div className="relative z-10 flex-1" />

      {/* Card */}
      <div className="relative z-10 bg-white rounded-t-3xl border-t border-black/[0.07] shadow-[0_-8px_40px_rgba(0,0,0,0.10)] px-6 pt-7 pb-10">
        <p className="text-[13px] text-slate-400 text-center mb-6 leading-relaxed">
          Sign in with the credentials provided by your System Administrator.
        </p>
        <form onSubmit={handleLogin} className="flex flex-col gap-4">

          {/* Email */}
          <div className="flex flex-col gap-2">
            <label className="text-[11px] font-medium text-slate-500 tracking-widest uppercase px-0.5">Email</label>
            <div className="flex items-center gap-3 px-4 py-3.5 border border-black/[0.12] rounded-xl bg-slate-50/80 focus-within:border-cyan-400 focus-within:bg-white focus-within:ring-2 focus-within:ring-cyan-500/10 transition-all">
              <span className="text-slate-400 flex-shrink-0"><MailIcon /></span>
              <input
                type="email"
                placeholder="driver@atice.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                autoComplete="email"
                inputMode="email"
                autoCapitalize="none"
                className="flex-1 bg-transparent text-[15px] text-slate-800 placeholder-slate-400 outline-none min-w-0"
              />
            </div>
          </div>

          {/* Password */}
          <div className="flex flex-col gap-2">
            <label className="text-[11px] font-medium text-slate-500 tracking-widest uppercase px-0.5">Password</label>
            <div className="flex items-center gap-3 px-4 py-3.5 border border-black/[0.12] rounded-xl bg-slate-50/80 focus-within:border-cyan-400 focus-within:bg-white focus-within:ring-2 focus-within:ring-cyan-500/10 transition-all">
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
                className="text-slate-400 active:text-slate-600 transition-colors flex-shrink-0 p-1 -mr-1 cursor-pointer"
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
            className="mt-2 w-full py-4 bg-cyan-500 active:bg-cyan-600 text-white text-[15px] font-semibold rounded-xl transition-colors duration-150 flex items-center justify-center gap-2.5 cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed shadow-lg shadow-cyan-500/25"
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

        <p className="text-center text-[11px] text-slate-400 mt-6">
          Having trouble?{" "}
          <span className="text-cyan-500 cursor-pointer">Contact your administrator</span>
        </p>
        <p className="text-center text-[10px] text-slate-300 mt-4 tracking-wide">
          © 2026 AT-ICE ERP · Driver Module v1.0
        </p>
      </div>
    </div>
  );
}