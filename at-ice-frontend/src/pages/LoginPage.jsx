import { useState } from "react"
import axios from "axios"

function LoginPage() {
  const [selectedRole, setSelectedRole] = useState("")
  const [email, setEmail] = useState("")
  const [password, setPassword] = useState("")
  const [error, setError] = useState("")
  const [loading, setLoading] = useState(false)

  const roles = [
    { key: "admin", label: "Admin", icon: "👤", desc: "Full system access" },
    { key: "employee", label: "Employee", icon: "🧑‍💼", desc: "Attendance & Inventory" },
    { key: "driver", label: "Driver", icon: "🚚", desc: "Deliveries & GPS" },
    { key: "customer", label: "Customer", icon: "🙋‍♂️", desc: "Orders & Tracking" },
  ]

  const handleLogin = async (e) => {
    e.preventDefault()
    setLoading(true)
    setError("")
    try {
      const response = await axios.post("http://127.0.0.1:8000/api/login", {
        email,
        password,
      })
      const { token, user } = response.data
      if (user.role !== selectedRole) {
        setError("Invalid credentials for selected role!")
        setLoading(false)
        return
      }
      localStorage.setItem("token", token)
      localStorage.setItem("role", user.role)
      if (user.role === "admin") window.location.href = "/admin/dashboard"
      else if (user.role === "employee") window.location.href = "/employee/dashboard"
      else if (user.role === "driver") window.location.href = "/driver/dashboard"
      else if (user.role === "customer") window.location.href = "/customer/dashboard"
    } catch (err) {
      setError("Invalid email or password!")
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center" style={{backgroundColor: "#0B1E3C"}}>
      <div className="bg-white rounded-2xl shadow-2xl p-10 w-full max-w-lg">
        <div className="text-center mb-8">
          <h1 className="text-3xl font-bold" style={{color: "#225597"}}>AT-ICE</h1>
          <p className="text-gray-500 text-sm mt-1">Alexandra Tube Ice Manufacturing</p>
          <div className="w-16 h-1 mx-auto mt-3 rounded" style={{backgroundColor: "#225597"}}></div>
        </div>

        {!selectedRole && (
          <div>
            <p className="text-center text-gray-700 font-semibold mb-4">Select your role to continue</p>
            <div className="grid grid-cols-2 gap-4">
              {roles.map((role) => (
                <button
                  key={role.key}
                  onClick={() => setSelectedRole(role.key)}
                  className="flex flex-col items-center justify-center p-6 rounded-xl border-2 hover:shadow-md transition"
                  style={{borderColor: "#225597"}}
                >
                  <span className="text-4xl mb-2">{role.icon}</span>
                  <span className="font-bold text-gray-800">{role.label}</span>
                  <span className="text-xs text-gray-500 mt-1">{role.desc}</span>
                </button>
              ))}
            </div>
            <div className="text-center mt-6">
              <a href="/" className="text-sm hover:underline" style={{color: "#225597"}}>
                Back to Home
              </a>
            </div>
          </div>
        )}

        {selectedRole && (
          <div>
            <div className="flex items-center justify-center gap-2 mb-6">
              <span className="text-2xl">{roles.find((r) => r.key === selectedRole)?.icon}</span>
              <span className="font-bold text-lg" style={{color: "#225597"}}>
                {roles.find((r) => r.key === selectedRole)?.label} Login
              </span>
            </div>
            {error && (
              <div className="bg-red-100 text-red-600 text-sm px-4 py-3 rounded-lg mb-4 text-center">
                {error}
              </div>
            )}
            <form onSubmit={handleLogin}>
              <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="Enter your email"
                  required
                  className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none text-gray-800"
                />
              </div>
              <div className="mb-6">
                <label className="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Enter your password"
                  required
                  className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none text-gray-800"
                />
              </div>
              <button
                type="submit"
                disabled={loading}
                className="w-full text-white font-bold py-3 rounded-lg transition"
                style={{backgroundColor: "#225597"}}
              >
                {loading ? "Signing in..." : "Sign In"}
              </button>
            </form>
            <div className="text-center mt-4">
              <button
                onClick={() => {setSelectedRole(""); setError(""); setEmail(""); setPassword("")}}
                className="text-sm hover:underline"
                style={{color: "#225597"}}
              >
                Change Role
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

export default LoginPage