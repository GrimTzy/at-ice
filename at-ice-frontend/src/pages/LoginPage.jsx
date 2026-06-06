import { useState } from "react";
import axios from "axios";
import { Shield, Users, Truck, UserCircle, ArrowRight, Snowflake } from "lucide-react";
 
function LoginPage() {
  const [selectedRole, setSelectedRole] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
 
  const roles = [
    {
      key: "admin",
      label: "Continue as Admin",
      desc: "Access admin dashboard and management tools",
      icon: Shield,
      gradient: "from-cyan-500 to-blue-600",
    },
    {
      key: "employee",
      label: "Continue as Employee",
      desc: "Access employee portal and daily tasks",
      icon: Users,
      gradient: "from-emerald-500 to-teal-600",
    },
    {
      key: "driver",
      label: "Continue as Driver",
      desc: "View deliveries and update order status",
      icon: Truck,
      gradient: "from-blue-500 to-indigo-600",
    },
    {
      key: "customer",
      label: "Continue as Customer",
      desc: "View orders and track deliveries",
      icon: UserCircle,
      gradient: "from-violet-500 to-purple-600",
    },
  ];
 
  const handleLogin = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError("");
    try {
      const response = await axios.post("http://127.0.0.1:8000/api/login", {
        email,
        password,
      });
      const { token, user } = response.data;
      if (user.role !== selectedRole) {
        setError("Invalid credentials for selected role!");
        setLoading(false);
        return;
      }
      localStorage.setItem("token", token);
      localStorage.setItem("role", user.role);
      if (user.role === "admin") window.location.href = "/admin/dashboard";
      else if (user.role === "employee") window.location.href = "/employee/dashboard";
      else if (user.role === "driver") window.location.href = "/driver/dashboard";
      else if (user.role === "customer") window.location.href = "/customer/dashboard";
    } catch (err) {
      setError("Invalid email or password!");
    } finally {
      setLoading(false);
    }
  };
 
  return (
    <div className="min-h-screen relative flex items-center justify-center p-4 bg-slate-950">
      {/* Background */}
      <div className="absolute inset-0">
        <img src="/icetube.png" alt="bg" className="w-full h-full object-cover opacity-20" />
        <div className="absolute inset-0 bg-slate-950/80"></div>
      </div>
 
      {/* Content */}
      <div className="relative w-full max-w-6xl">
        <div className="grid lg:grid-cols-2 gap-8 lg:gap-12 items-center">
 
          {/* Left Side - Welcome */}
          <div className="text-center lg:text-left space-y-6">
            <div className="flex items-center gap-3 justify-center lg:justify-start">
              <div className="w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-blue-600 flex items-center justify-center">
                <Snowflake className="w-7 h-7 text-white" />
              </div>
              <div className="flex flex-col">
                <span className="text-white text-2xl tracking-wider font-semibold">
                  AT-<span className="text-cyan-400">ICE</span>
                </span>
                <span className="text-xs text-cyan-400/70">TUBE ICE SUPPLY</span>
              </div>
            </div>
 
            <div className="space-y-4">
              <h1 className="text-4xl sm:text-5xl lg:text-6xl text-white leading-tight">
                Welcome
                <br />
                <span className="text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-500">
                  Back!
                </span>
              </h1>
              <p className="text-lg text-white/70 max-w-md mx-auto lg:mx-0">
                Login to access your portal and manage your daily operations.
              </p>
            </div>
 
            <div className="flex items-center gap-2 text-white/50 text-sm justify-center lg:justify-start">
              <div className="w-2 h-2 bg-cyan-400 rounded-full animate-pulse"></div>
              <span>Secure employee access</span>
            </div>
          </div>
 
          {/* Right Side - Login Card */}
          <div className="w-full max-w-md mx-auto">
            <div className="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5 sm:p-6 shadow-2xl">
 
              {/* Header */}
              <div className="text-center mb-6">
                <h2 className="text-xl sm:text-2xl text-white mb-1">Login to Your Account</h2>
                <p className="text-white/60 text-sm">Select your role to continue</p>
              </div>
 
              {/* Role Selection */}
              <div className="space-y-3 mb-5">
                {roles.map((role) => {
                  const Icon = role.icon;
                  return (
                    <button
                      key={role.key}
                      onClick={() => { setSelectedRole(role.key); setError(""); }}
                      className={`w-full group relative overflow-hidden border rounded-xl p-3.5 transition-all duration-300 text-left
                        ${selectedRole === role.key
                          ? "bg-white/15 border-cyan-400/70"
                          : "bg-white/5 hover:bg-white/10 border-white/10 hover:border-cyan-400/50"
                        }`}
                    >
                      <div className="flex items-center gap-3">
                        <div className={`w-10 h-10 rounded-lg bg-gradient-to-br ${role.gradient} flex items-center justify-center group-hover:scale-110 transition-transform`}>
                          <Icon className="w-5 h-5 text-white" />
                        </div>
                        <div className="flex-1">
                          <div className={`text-sm mb-0.5 transition-colors ${selectedRole === role.key ? "text-cyan-400" : "text-white group-hover:text-cyan-400"}`}>
                            {role.label}
                          </div>
                          <div className="text-white/50 text-xs">{role.desc}</div>
                        </div>
                        <ArrowRight className={`w-4 h-4 transition-all ${selectedRole === role.key ? "text-cyan-400 translate-x-1" : "text-white/30 group-hover:text-cyan-400 group-hover:translate-x-1"}`} />
                      </div>
                    </button>
                  );
                })}
              </div>
 
              {/* Divider */}
              <div className="relative my-5">
                <div className="absolute inset-0 flex items-center">
                  <div className="w-full border-t border-white/10"></div>
                </div>
                <div className="relative flex justify-center text-xs">
                  <span className="px-4 py-1 bg-slate-950 text-white/50">Or sign in with credentials</span>
                </div>
              </div>
 
              {/* Error */}
              {error && (
                <div className="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-4 text-center">
                  {error}
                </div>
              )}
 
              {/* Login Form */}
              <form onSubmit={handleLogin} className="space-y-3">
                <div>
                  <label className="block text-white/70 text-xs mb-1.5">Email Address</label>
                  <input
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="your.email@at-ice.com"
                    required
                    className="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-lg text-white text-sm placeholder:text-white/30 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-transparent transition-all"
                  />
                </div>
 
                <div>
                  <label className="block text-white/70 text-xs mb-1.5">Password</label>
                  <input
                    type="password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="••••••••"
                    required
                    className="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-lg text-white text-sm placeholder:text-white/30 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-transparent transition-all"
                  />
                </div>
 
                <div className="flex items-center justify-between text-xs">
                  <label className="flex items-center gap-2 text-white/70 cursor-pointer group">
                    <input type="checkbox" className="w-3.5 h-3.5 rounded border-white/20 bg-white/5 text-cyan-500 focus:ring-2 focus:ring-cyan-500 focus:ring-offset-0" />
                    <span className="group-hover:text-white transition-colors">Remember me</span>
                  </label>
                  <a href="#" className="text-cyan-400 hover:text-cyan-300 transition-colors">
                    Forgot password?
                  </a>
                </div>
 
                <button
                  type="submit"
                  disabled={loading || !selectedRole}
                  className="w-full inline-flex items-center justify-center gap-2 bg-cyan-500 hover:bg-cyan-600 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium py-2.5 rounded-lg transition-all duration-200 shadow-lg shadow-cyan-500/20"
                >
                  {loading ? "Signing in..." : "Sign In"}
                  {!loading && <ArrowRight className="w-4 h-4" />}
                </button>
              </form>
 
              {/* Footer */}
              <p className="text-center text-white/40 text-xs mt-4">
                Need help? Contact IT support
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
 
export default LoginPage;