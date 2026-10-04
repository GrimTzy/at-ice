import { useState } from "react";
import { useNavigate } from "react-router-dom";
import logo from "../../assets/logo.png";
import bgImage from "../../assets/iceproduct.png";

export default function CustomerLogin() {
  const navigate = useNavigate();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [showHelp, setShowHelp] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const res = await fetch("/api/login", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email, password }),
      });
      const data = await res.json().catch(() => null);

      if (!res.ok) {
        throw new Error(data?.message || "Invalid credentials.");
      }
      if (data.role !== "customer") {
        throw new Error("This account is not a Customer Portal account.");
      }

      localStorage.setItem("token", data.token);
      localStorage.setItem("role", data.role);
      localStorage.setItem("sub_role", data.sub_role || "");
      localStorage.setItem("user", JSON.stringify(data.user));

      // A new account must replace its one-time password before using the portal.
      if (data.must_change_password) {
        localStorage.setItem("must_change_password", "1");
        navigate("/customer/change-password");
        return;
      }
      localStorage.removeItem("must_change_password");
      navigate("/customer/dashboard");
    } catch (err) {
      setError(err.message || "Something went wrong. Please try again.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div
      className="relative min-h-screen w-full flex items-center justify-center p-4"
      style={{
        backgroundImage: `linear-gradient(rgba(10,13,18,0.55), rgba(10,13,18,0.65)), url(${bgImage})`,
        backgroundSize: "cover",
        backgroundPosition: "center",
      }}
    >
      <div className="relative z-10 w-full max-w-[400px]">
        <div className="flex flex-col items-center text-center mb-6">
          <div className="w-14 h-14 bg-slate-900 border border-cyan-500/30 rounded-2xl flex items-center justify-center shadow-xl mb-3 overflow-hidden">
            <img src={logo} alt="Bella Erin logo" className="w-full h-full object-contain p-2" />
          </div>
          <h1 className="text-2xl font-bold text-white drop-shadow-md">Bella Erin</h1>
          <p className="text-[11px] font-bold text-cyan-300 uppercase tracking-widest mt-0.5">AT-ICE ERP</p>
          <div className="mt-2.5 inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-slate-900/80 border border-cyan-500/40 text-cyan-300 text-[11px] font-medium">
            <span className="w-1.5 h-1.5 rounded-full bg-cyan-400" />
            Customer Portal
          </div>
        </div>

        <div className="w-full rounded-2xl bg-white shadow-2xl p-7 sm:p-8">
          <div className="mb-6">
            <h2 className="text-xl font-bold text-slate-900">Sign in to your account</h2>
            <p className="text-xs text-slate-500 mt-1">Enter the credentials given to you by Bella Erin's Admin.</p>
          </div>

          {error && (
            <div className="mb-4 px-3 py-2.5 rounded-xl bg-red-50 border border-red-200 text-red-600 text-xs font-medium">
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1.5">
                Email Address
              </label>
              <input
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="e.g. your-store@atice-portal.com"
                className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20"
              />
            </div>

            <div>
              <label className="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1.5">
                Password
              </label>
              <div className="relative">
                <input
                  type={showPassword ? "text" : "password"}
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Enter your password"
                  className="w-full px-3.5 py-2.5 pr-16 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((s) => !s)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-cyan-600 font-medium"
                >
                  {showPassword ? "Hide" : "Show"}
                </button>
              </div>
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full py-3 bg-cyan-500 hover:bg-cyan-600 disabled:opacity-60 text-white font-semibold rounded-xl text-sm transition-colors"
            >
              {loading ? "Signing in..." : "Sign In"}
            </button>

            <p className="text-center text-xs text-slate-500">
              Having trouble?{" "}
              <button
                type="button"
                onClick={() => setShowHelp(true)}
                className="text-cyan-600 hover:text-cyan-700 font-semibold underline underline-offset-2"
              >
                Contact IT support
              </button>
            </p>
          </form>
        </div>

        <div className="mt-6 text-center text-xs text-slate-300">
          <p>© 2026 AT-ICE ERP · Customer Module v1.0</p>
          <p className="text-[10px] text-slate-400 mt-0.5">Bella Erin Tube Ice Inc. · All Rights Reserved</p>
        </div>
      </div>

      {showHelp && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60" onClick={() => setShowHelp(false)}>
          <div className="bg-white rounded-2xl p-6 max-w-sm w-full space-y-4" onClick={(e) => e.stopPropagation()}>
            <h3 className="font-bold text-slate-900">IT Support Assistance</h3>
            <p className="text-xs text-slate-600 leading-relaxed">
              Customer Portal accounts are provisioned directly by the Bella Erin Admin. If you've forgotten
              your password or need a new account, contact us using the details below.
            </p>
            <div className="p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs space-y-1">
              <p className="text-slate-500">Dispatch Room: <span className="text-cyan-700 font-mono font-semibold">(02) 8352-9411</span></p>
              <p className="text-slate-500">Email: <span className="text-cyan-700 font-mono font-semibold">support@atice-erp.ph</span></p>
            </div>
            <button
              onClick={() => setShowHelp(false)}
              className="w-full py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 rounded-xl text-xs font-semibold"
            >
              Got it, close
            </button>
          </div>
        </div>
      )}
    </div>
  );
}