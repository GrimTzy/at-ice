import { useState } from "react";
import { useNavigate } from "react-router-dom";
import logo from "../../assets/logo.png";
import { clearSession } from "../../lib/auth";
import PasswordRules from "../../components/PasswordRules";
import { isStrongPassword } from "../../lib/password";

// Shown on first login. A new customer account starts with a one-time password
// and cannot use the portal until it is changed (the server enforces this too).
export default function CustomerChangePassword() {
  const navigate = useNavigate();
  const [form, setForm] = useState({ current_password: "", password: "", password_confirmation: "" });
  const [show, setShow] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const mismatch = form.password_confirmation.length > 0 && form.password !== form.password_confirmation;
  const canSubmit =
    form.current_password && isStrongPassword(form.password) && form.password === form.password_confirmation;

  const signOut = async () => {
    try {
      await fetch("/api/logout", {
        method: "POST",
        headers: { Accept: "application/json", Authorization: `Bearer ${localStorage.getItem("token")}` },
      });
    } catch {
      // the local session is cleared either way
    }
    clearSession();
    navigate("/login", { replace: true });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError("");
    try {
      const res = await fetch("/api/change-password", {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${localStorage.getItem("token")}`,
        },
        body: JSON.stringify(form),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        const firstFieldError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstFieldError || data.message || "Could not change the password.");
      }
      localStorage.removeItem("must_change_password");
      navigate("/customer/dashboard", { replace: true });
    } catch (err) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  };

  const inputCls =
    "w-full bg-slate-50 border border-black/[0.09] rounded-lg px-3 py-2 text-[13px] outline-none focus:border-cyan-500";

  return (
    <div className="min-h-screen w-full flex items-center justify-center p-4 bg-slate-100">
      <div className="w-full max-w-[400px]">
        <div className="flex flex-col items-center text-center mb-6">
          <div className="w-14 h-14 bg-slate-900 border border-cyan-500/30 rounded-2xl flex items-center justify-center shadow-xl mb-3 overflow-hidden">
            <img src={logo} alt="Bella Erin logo" className="w-full h-full object-contain p-2" />
          </div>
          <h1 className="text-2xl font-bold text-slate-900">Bella Erin</h1>
          <p className="text-[11px] font-bold text-cyan-600 uppercase tracking-widest mt-0.5">Customer Portal</p>
        </div>

        <form onSubmit={handleSubmit} className="w-full rounded-2xl bg-white shadow-2xl p-7 sm:p-8 flex flex-col gap-3">
          <div className="mb-2">
            <h2 className="text-xl font-bold text-slate-900">Set your own password</h2>
            <p className="text-xs text-slate-500 mt-1">
              You signed in with a one-time password. Choose a new one to continue to your account.
            </p>
          </div>

          {error && (
            <div className="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-[12px] text-red-700">{error}</div>
          )}

          <label className="flex flex-col gap-1">
            <span className="text-[11px] text-slate-500">One-time password</span>
            <input
              type={show ? "text" : "password"}
              autoComplete="current-password"
              value={form.current_password}
              onChange={(e) => setForm({ ...form, current_password: e.target.value })}
              className={inputCls}
              required
            />
          </label>
          <label className="flex flex-col gap-1">
            <span className="text-[11px] text-slate-500">New password</span>
            <input
              type={show ? "text" : "password"}
              autoComplete="new-password"
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
              className={inputCls}
              required
              minLength={8}
            />
            <PasswordRules password={form.password} />
          </label>
          <label className="flex flex-col gap-1">
            <span className="text-[11px] text-slate-500">Confirm new password</span>
            <input
              type={show ? "text" : "password"}
              autoComplete="new-password"
              value={form.password_confirmation}
              onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
              className={inputCls}
              required
            />
            {mismatch && <span className="text-[10px] text-red-500">The passwords do not match.</span>}
          </label>

          <label className="flex items-center gap-2 text-[11px] text-slate-500">
            <input type="checkbox" checked={show} onChange={(e) => setShow(e.target.checked)} />
            Show passwords
          </label>

          <button
            type="submit"
            disabled={!canSubmit || saving}
            className="mt-1 w-full py-2.5 rounded-lg bg-cyan-500 hover:bg-cyan-600 text-white text-[13px] font-semibold disabled:opacity-50"
          >
            {saving ? "Saving…" : "Change password and continue"}
          </button>
          <button type="button" onClick={signOut} className="text-[11px] text-slate-400 hover:text-slate-600">
            Sign out
          </button>
        </form>
      </div>
    </div>
  );
}
