import { useState, useEffect } from "react";
import { motion, AnimatePresence } from "motion/react";
import { Toaster, toast } from "sonner";
import {
  Home, Clock, Users, Settings, FileText, BarChart3, Menu, X,
  Camera, Scan, CheckCircle2, XCircle, Loader2, Clock as ClockIcon,
  User, Calendar, Building2, Fingerprint, Shield
} from "lucide-react";

// ─────────────────────────────────────────
// ImageWithFallback
// ─────────────────────────────────────────
const ERROR_IMG_SRC =
  'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iODgiIGhlaWdodD0iODgiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgc3Ryb2tlPSIjMDAwIiBzdHJva2UtbGluZWpvaW49InJvdW5kIiBvcGFjaXR5PSIuMyIgZmlsbD0ibm9uZSIgc3Ryb2tlLXdpZHRoPSIzLjciPjxyZWN0IHg9IjE2IiB5PSIxNiIgd2lkdGg9IjU2IiBoZWlnaHQ9IjU2IiByeD0iNiIvPjxwYXRoIGQ9Im0xNiA1OCAxNi0xOCAzMiAzMiIvPjxjaXJjbGUgY3g9IjUzIiBjeT0iMzUiIHI9IjciLz48L3N2Zz4KCg==';

function ImageWithFallback({ src, alt, style, className, ...rest }) {
  const [didError, setDidError] = useState(false);
  if (didError) {
    return (
      <div className={`inline-block bg-gray-100 text-center align-middle ${className ?? ''}`} style={style}>
        <div className="flex items-center justify-center w-full h-full">
          <img src={ERROR_IMG_SRC} alt="Error loading image" {...rest} data-original-url={src} />
        </div>
      </div>
    );
  }
  return <img src={src} alt={alt} className={className} style={style} {...rest} onError={() => setDidError(true)} />;
}

// ─────────────────────────────────────────
// StatusIndicator
// ─────────────────────────────────────────
function StatusIndicator({ label, status }) {
  const getStatusConfig = () => {
    switch (status) {
      case "scanning":
        return { icon: Loader2, color: "text-blue-400", bgColor: "bg-blue-500/10", borderColor: "border-blue-500/30", animate: true };
      case "success":
        return { icon: CheckCircle2, color: "text-emerald-400", bgColor: "bg-emerald-500/10", borderColor: "border-emerald-500/30", animate: false };
      case "failed":
        return { icon: XCircle, color: "text-red-400", bgColor: "bg-red-500/10", borderColor: "border-red-500/30", animate: false };
      default:
        return { icon: CheckCircle2, color: "text-slate-500", bgColor: "bg-slate-500/5", borderColor: "border-slate-500/20", animate: false };
    }
  };

  const config = getStatusConfig();
  const Icon = config.icon;

  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      className={`flex items-center gap-3 px-4 py-3 rounded-xl border ${config.bgColor} ${config.borderColor} backdrop-blur-sm`}
    >
      <motion.div
        animate={config.animate ? { rotate: 360 } : {}}
        transition={config.animate ? { duration: 1, repeat: Infinity, ease: "linear" } : {}}
      >
        <Icon className={`w-5 h-5 ${config.color}`} />
      </motion.div>
      <div className="flex-1">
        <div className="text-sm text-slate-300">{label}</div>
      </div>
      <div className={`text-xs uppercase tracking-wider ${config.color}`}>
        {status === "idle" ? "Ready" : status === "scanning" ? "Scanning" : status === "success" ? "Verified" : "Failed"}
      </div>
    </motion.div>
  );
}

// ─────────────────────────────────────────
// CameraFeed
// ─────────────────────────────────────────
function CameraFeed({ isScanning }) {
  return (
    <div className="relative w-full aspect-[4/3] bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 rounded-2xl overflow-hidden border border-slate-700/50 shadow-2xl">
      <div className="absolute inset-0 bg-[radial-gradient(circle_at_center,rgba(100,116,139,0.1)_0%,transparent_70%)]" />
      <div className="absolute inset-0 flex items-center justify-center">
        <div className="relative">
          <Camera className="w-24 h-24 text-slate-600" strokeWidth={1.5} />
          {isScanning && (
            <motion.div
              className="absolute inset-0"
              initial={{ opacity: 0 }}
              animate={{ opacity: [0.3, 0.6, 0.3] }}
              transition={{ duration: 2, repeat: Infinity }}
            >
              <Scan className="w-24 h-24 text-blue-400" strokeWidth={1.5} />
            </motion.div>
          )}
        </div>
      </div>
      {isScanning && (
        <>
          <motion.div
            className="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-blue-500 to-transparent"
            initial={{ y: 0 }}
            animate={{ y: "calc(100vh - 4px)" }}
            transition={{ duration: 2, repeat: Infinity, ease: "linear" }}
          />
          <div className="absolute top-4 left-4 w-16 h-16 border-l-2 border-t-2 border-blue-400" />
          <div className="absolute top-4 right-4 w-16 h-16 border-r-2 border-t-2 border-blue-400" />
          <div className="absolute bottom-4 left-4 w-16 h-16 border-l-2 border-b-2 border-blue-400" />
          <div className="absolute bottom-4 right-4 w-16 h-16 border-r-2 border-b-2 border-blue-400" />
        </>
      )}
      <div className="absolute inset-0 opacity-10">
        <div className="w-full h-full grid grid-cols-3 grid-rows-3">
          {[...Array(9)].map((_, i) => (
            <div key={i} className="border border-slate-500" />
          ))}
        </div>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────
// VerificationSuccess
// ─────────────────────────────────────────
function VerificationSuccess({ show, employeeName, role, timeIn, onComplete }) {
  useEffect(() => {
    if (show) {
      const timer = setTimeout(onComplete, 4000);
      return () => clearTimeout(timer);
    }
  }, [show, onComplete]);

  return (
    <AnimatePresence>
      {show && (
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          className="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4"
        >
          <motion.div
            initial={{ scale: 0.8, opacity: 0 }}
            animate={{ scale: 1, opacity: 1 }}
            exit={{ scale: 0.9, opacity: 0 }}
            transition={{ type: "spring", duration: 0.5 }}
            className="bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 border border-emerald-500/30 rounded-3xl p-8 max-w-md w-full shadow-2xl"
          >
            <motion.div
              initial={{ scale: 0 }}
              animate={{ scale: 1 }}
              transition={{ delay: 0.2, type: "spring", stiffness: 200 }}
              className="flex justify-center mb-6"
            >
              <div className="relative">
                <motion.div
                  initial={{ scale: 0 }}
                  animate={{ scale: 1.2 }}
                  transition={{ delay: 0.3, duration: 0.4 }}
                  className="absolute inset-0 bg-emerald-500/20 rounded-full blur-xl"
                />
                <CheckCircle2 className="w-20 h-20 text-emerald-400 relative" strokeWidth={1.5} />
              </div>
            </motion.div>
            <motion.div
              initial={{ y: 20, opacity: 0 }}
              animate={{ y: 0, opacity: 1 }}
              transition={{ delay: 0.4 }}
              className="text-center mb-6"
            >
              <h2 className="text-2xl text-white mb-2">Verification Successful</h2>
              <p className="text-slate-400">Access granted. Welcome back!</p>
            </motion.div>
            <motion.div
              initial={{ y: 20, opacity: 0 }}
              animate={{ y: 0, opacity: 1 }}
              transition={{ delay: 0.5 }}
              className="space-y-3"
            >
              <div className="flex items-center gap-3 bg-slate-800/50 border border-slate-700/50 rounded-xl px-4 py-3">
                <User className="w-5 h-5 text-blue-400" />
                <div className="flex-1">
                  <div className="text-xs text-slate-500 uppercase tracking-wider">Employee</div>
                  <div className="text-white">{employeeName}</div>
                </div>
              </div>
              <div className="flex items-center gap-3 bg-slate-800/50 border border-slate-700/50 rounded-xl px-4 py-3">
                <Shield className="w-5 h-5 text-purple-400" />
                <div className="flex-1">
                  <div className="text-xs text-slate-500 uppercase tracking-wider">Role</div>
                  <div className="text-white">{role}</div>
                </div>
              </div>
              <div className="flex items-center gap-3 bg-slate-800/50 border border-slate-700/50 rounded-xl px-4 py-3">
                <Clock className="w-5 h-5 text-emerald-400" />
                <div className="flex-1">
                  <div className="text-xs text-slate-500 uppercase tracking-wider">Time In</div>
                  <div className="text-white">{timeIn}</div>
                </div>
              </div>
            </motion.div>
            <motion.div
              initial={{ scaleX: 0 }}
              animate={{ scaleX: 1 }}
              transition={{ delay: 0.6, duration: 3.4 }}
              className="mt-6 h-1 bg-emerald-500/30 rounded-full overflow-hidden"
            >
              <motion.div
                initial={{ x: "-100%" }}
                animate={{ x: "0%" }}
                transition={{ delay: 0.6, duration: 3.4, ease: "linear" }}
                className="h-full bg-gradient-to-r from-emerald-500 to-emerald-400"
              />
            </motion.div>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}

// ─────────────────────────────────────────
// AttendanceLog
// ─────────────────────────────────────────
function AttendanceLog({ records }) {
  return (
    <div className="bg-slate-900/50 border border-slate-800/50 rounded-2xl backdrop-blur-sm overflow-hidden">
      <div className="p-6 border-b border-slate-800/50">
        <h2 className="text-xl text-white flex items-center gap-2">
          <Clock className="w-5 h-5 text-blue-400" />
          Attendance Logs
        </h2>
        <p className="text-sm text-slate-400 mt-1">Real-time verification records</p>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full">
          <thead className="bg-slate-800/30">
            <tr>
              {["Employee", "Role", "Date", "Time In", "Verification", "Status"].map((h) => (
                <th key={h} className="px-6 py-4 text-left text-xs text-slate-400 uppercase tracking-wider">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-800/50">
            {records.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-6 py-12 text-center text-slate-500">
                  No attendance records yet. Start verification to create logs.
                </td>
              </tr>
            ) : (
              records.map((record, index) => (
                <motion.tr
                  key={record.id}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: index * 0.05 }}
                  className="hover:bg-slate-800/20 transition-colors"
                >
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-500 rounded-full flex items-center justify-center flex-shrink-0">
                        <User className="w-5 h-5 text-white" />
                      </div>
                      <div>
                        <div className="text-white">{record.employeeName}</div>
                        <div className="text-xs text-slate-500">{record.employeeId}</div>
                      </div>
                    </div>
                  </td>
                  <td className="px-6 py-4"><span className="text-slate-300">{record.role}</span></td>
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-2 text-slate-300">
                      <Calendar className="w-4 h-4 text-slate-500" />{record.date}
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-2">
                      <Clock className="w-4 h-4 text-blue-400" />
                      <span className="text-white font-mono">{record.timeIn}</span>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-2">
                      <div className="flex items-center gap-1">
                        {record.faceVerified ? <CheckCircle2 className="w-4 h-4 text-emerald-400" /> : <XCircle className="w-4 h-4 text-red-400" />}
                        <span className="text-xs text-slate-400">Face</span>
                      </div>
                      <div className="flex items-center gap-1">
                        {record.uniformVerified ? <CheckCircle2 className="w-4 h-4 text-emerald-400" /> : <XCircle className="w-4 h-4 text-red-400" />}
                        <span className="text-xs text-slate-400">Uniform</span>
                      </div>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    {record.status === "success" ? (
                      <span className="inline-flex items-center gap-1 px-3 py-1 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 rounded-full text-xs">
                        <CheckCircle2 className="w-3 h-3" /> Verified
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 px-3 py-1 bg-red-500/10 border border-red-500/30 text-red-400 rounded-full text-xs">
                        <XCircle className="w-3 h-3" /> Failed
                      </span>
                    )}
                  </td>
                </motion.tr>
              ))
            )}
          </tbody>
        </table>
      </div>
      {records.length > 0 && (
        <div className="px-6 py-4 border-t border-slate-800/50 flex items-center justify-between text-sm text-slate-400">
          <div>Showing <span className="text-white">{records.length}</span> record(s)</div>
          <div className="flex items-center gap-2">
            <div className="flex items-center gap-1">
              <div className="w-2 h-2 bg-emerald-500 rounded-full" />
              <span>{records.filter((r) => r.status === "success").length} Verified</span>
            </div>
            <div className="w-px h-4 bg-slate-700" />
            <div className="flex items-center gap-1">
              <div className="w-2 h-2 bg-red-500 rounded-full" />
              <span>{records.filter((r) => r.status === "failed").length} Failed</span>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// ─────────────────────────────────────────
// Dashboard
// ─────────────────────────────────────────
function Dashboard({ onNewRecord }) {
  const [currentTime, setCurrentTime] = useState(new Date());
  const [faceStatus, setFaceStatus] = useState("idle");
  const [uniformStatus, setUniformStatus] = useState("idle");
  const [showSuccess, setShowSuccess] = useState(false);
  const [isScanning, setIsScanning] = useState(false);

  const [employeeData] = useState({
    name: "Sarah Martinez",
    role: "Senior Software Engineer",
    employeeId: "EMP-2847",
  });

  useEffect(() => {
    const timer = setInterval(() => setCurrentTime(new Date()), 1000);
    return () => clearInterval(timer);
  }, []);

  const formatTime = (date) =>
    date.toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: true });

  const formatDate = (date) =>
    date.toLocaleDateString("en-US", { weekday: "long", year: "numeric", month: "long", day: "numeric" });

  const startVerification = () => {
    setIsScanning(true);
    setFaceStatus("scanning");
    setUniformStatus("scanning");

    setTimeout(() => { setFaceStatus("success"); toast.success("Face recognized successfully"); }, 1500);
    setTimeout(() => { setUniformStatus("success"); toast.success("Uniform verified"); }, 2500);
    setTimeout(() => {
      setIsScanning(false);
      const now = new Date();
      const newRecord = {
        id: `record-${Date.now()}`,
        employeeName: employeeData.name,
        employeeId: employeeData.employeeId,
        role: employeeData.role,
        timeIn: now.toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: true }),
        date: now.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" }),
        faceVerified: true,
        uniformVerified: true,
        status: "success",
      };
      onNewRecord(newRecord);
      setShowSuccess(true);
    }, 3000);
  };

  const resetVerification = () => {
    setShowSuccess(false);
    setFaceStatus("idle");
    setUniformStatus("idle");
  };

  return (
    <>
      <motion.div initial={{ y: -20, opacity: 0 }} animate={{ y: 0, opacity: 1 }} className="mb-8">
        <div className="flex items-center justify-between mb-2">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-blue-500/10 border border-blue-500/30 rounded-xl">
              <Building2 className="w-6 h-6 text-blue-400" />
            </div>
            <div>
              <h1 className="text-3xl">Attendance System</h1>
              <p className="text-slate-400">Automated Time & Verification Portal</p>
            </div>
          </div>
          <div className="text-right">
            <div className="text-3xl font-mono tracking-tight text-slate-200">{formatTime(currentTime)}</div>
            <div className="text-sm text-slate-400">{formatDate(currentTime)}</div>
          </div>
        </div>
      </motion.div>

      <div className="grid lg:grid-cols-2 gap-8">
        <motion.div initial={{ x: -20, opacity: 0 }} animate={{ x: 0, opacity: 1 }} transition={{ delay: 0.1 }}>
          <div className="bg-slate-900/50 border border-slate-800/50 rounded-2xl p-6 backdrop-blur-sm">
            <div className="flex items-center gap-2 mb-4">
              <div className="w-2 h-2 bg-red-500 rounded-full animate-pulse" />
              <h2 className="text-lg text-slate-300">Live Camera Feed</h2>
            </div>
            <CameraFeed isScanning={isScanning} />
            <div className="mt-6">
              <motion.button
                whileHover={{ scale: 1.02 }}
                whileTap={{ scale: 0.98 }}
                onClick={startVerification}
                disabled={isScanning}
                className="w-full bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 disabled:from-slate-700 disabled:to-slate-700 disabled:cursor-not-allowed text-white py-4 rounded-xl transition-all duration-200 flex items-center justify-center gap-2 shadow-lg shadow-blue-500/20"
              >
                <Fingerprint className="w-5 h-5" />
                {isScanning ? "Scanning..." : "Start Verification"}
              </motion.button>
            </div>
          </div>
        </motion.div>

        <motion.div initial={{ x: 20, opacity: 0 }} animate={{ x: 0, opacity: 1 }} transition={{ delay: 0.2 }} className="space-y-6">
          <div className="bg-slate-900/50 border border-slate-800/50 rounded-2xl p-6 backdrop-blur-sm">
            <h2 className="text-lg text-slate-300 mb-4">Current Session</h2>
            <div className="space-y-3">
              {[["Employee Name", employeeData.name], ["Role", employeeData.role], ["Employee ID", employeeData.employeeId]].map(([label, value], i) => (
                <div key={i} className={`flex justify-between items-center py-3 ${i < 2 ? "border-b border-slate-800/50" : ""}`}>
                  <span className="text-slate-400">{label}</span>
                  <span className="text-white font-mono">{value}</span>
                </div>
              ))}
            </div>
          </div>

          <div className="bg-slate-900/50 border border-slate-800/50 rounded-2xl p-6 backdrop-blur-sm">
            <h2 className="text-lg text-slate-300 mb-4">Verification Status</h2>
            <div className="space-y-3">
              <StatusIndicator label="Face Recognition" status={faceStatus} />
              <StatusIndicator label="Uniform Verification" status={uniformStatus} />
            </div>
          </div>

          <div className="bg-gradient-to-br from-blue-500/10 to-purple-500/10 border border-blue-500/20 rounded-2xl p-6 backdrop-blur-sm">
            <div className="flex items-start gap-3">
              <Clock className="w-5 h-5 text-blue-400 mt-1 flex-shrink-0" />
              <div>
                <h3 className="text-slate-200 mb-1">Auto Time-In Enabled</h3>
                <p className="text-sm text-slate-400">
                  Time will be automatically recorded upon successful verification of face recognition and uniform compliance.
                </p>
              </div>
            </div>
          </div>
        </motion.div>
      </div>

      <VerificationSuccess
        show={showSuccess}
        employeeName={employeeData.name}
        role={employeeData.role}
        timeIn={formatTime(currentTime)}
        onComplete={resetVerification}
      />
    </>
  );
}

// ─────────────────────────────────────────
// Sidebar
// ─────────────────────────────────────────
function Sidebar({ activeTab, onTabChange }) {
  const [isCollapsed, setIsCollapsed] = useState(false);

  const menuItems = [
    { id: "dashboard", label: "Dashboard", icon: Home },
    { id: "logs", label: "Attendance Logs", icon: Clock },
    { id: "employees", label: "Employees", icon: Users },
    { id: "reports", label: "Reports", icon: BarChart3 },
    { id: "records", label: "Records", icon: FileText },
    { id: "settings", label: "Settings", icon: Settings },
  ];

  return (
    <>
      <button
        onClick={() => setIsCollapsed(!isCollapsed)}
        className="lg:hidden fixed top-6 left-6 z-50 p-2 bg-slate-800 border border-slate-700 rounded-lg"
      >
        {isCollapsed ? <Menu className="w-5 h-5" /> : <X className="w-5 h-5" />}
      </button>

      <motion.div
        initial={{ x: -280 }}
        animate={{ x: 0 }}
        className={`fixed left-0 top-0 h-screen bg-slate-900/95 backdrop-blur-xl border-r border-slate-800/50 z-40 transition-all duration-300 ${isCollapsed ? "w-20" : "w-72"}`}
      >
        <div className="p-6 border-b border-slate-800/50">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-500 rounded-xl flex items-center justify-center flex-shrink-0">
              <Clock className="w-6 h-6 text-white" />
            </div>
            {!isCollapsed && (
              <div>
                <h1 className="text-lg text-white">AttendTrack</h1>
                <p className="text-xs text-slate-400">Pro v2.0</p>
              </div>
            )}
          </div>
        </div>

        <nav className="p-4 space-y-2">
          {menuItems.map((item) => {
            const Icon = item.icon;
            const isActive = activeTab === item.id;
            return (
              <motion.button
                key={item.id}
                whileHover={{ x: 4 }}
                whileTap={{ scale: 0.98 }}
                onClick={() => onTabChange(item.id)}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 ${
                  isActive
                    ? "bg-blue-500/20 border border-blue-500/30 text-blue-400"
                    : "text-slate-400 hover:bg-slate-800/50 hover:text-slate-200"
                }`}
              >
                <Icon className="w-5 h-5 flex-shrink-0" />
                {!isCollapsed && <span className="text-sm">{item.label}</span>}
                {!isCollapsed && isActive && (
                  <motion.div layoutId="activeTab" className="ml-auto w-1.5 h-1.5 bg-blue-400 rounded-full" />
                )}
              </motion.button>
            );
          })}
        </nav>

        <div className="absolute bottom-6 left-0 right-0 px-4">
          <button
            onClick={() => setIsCollapsed(!isCollapsed)}
            className="hidden lg:flex w-full items-center justify-center gap-2 px-4 py-3 bg-slate-800/50 hover:bg-slate-800 border border-slate-700/50 rounded-xl text-slate-400 hover:text-slate-200 transition-all duration-200"
          >
            <Menu className="w-4 h-4" />
            {!isCollapsed && <span className="text-sm">Collapse</span>}
          </button>
        </div>
      </motion.div>
    </>
  );
}

// ─────────────────────────────────────────
// Placeholder pages
// ─────────────────────────────────────────
function PlaceholderPage({ title, description }) {
  return (
    <div className="bg-slate-900/50 border border-slate-800/50 rounded-2xl p-12 backdrop-blur-sm text-center">
      <h2 className="text-2xl text-white mb-2">{title}</h2>
      <p className="text-slate-400">{description}</p>
    </div>
  );
}

// ─────────────────────────────────────────
// AttendanceStation (Main Export)
// ─────────────────────────────────────────
export default function AttendanceStation() {
  const [activeTab, setActiveTab] = useState("dashboard");
  const [attendanceRecords, setAttendanceRecords] = useState([]);

  const handleNewRecord = (record) => {
    setAttendanceRecords((prev) => [record, ...prev]);
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 text-white">
      <Toaster position="top-right" theme="dark" />
      <Sidebar activeTab={activeTab} onTabChange={setActiveTab} />
      <div className="lg:ml-72 p-6">
        <div className="max-w-7xl mx-auto">
          {activeTab === "dashboard" && <Dashboard onNewRecord={handleNewRecord} />}
          {activeTab === "logs" && <AttendanceLog records={attendanceRecords} />}
          {activeTab === "employees" && <PlaceholderPage title="Employees Management" description="This section is under development" />}
          {activeTab === "reports" && <PlaceholderPage title="Reports & Analytics" description="This section is under development" />}
          {activeTab === "records" && <PlaceholderPage title="Records Archive" description="This section is under development" />}
          {activeTab === "settings" && <PlaceholderPage title="System Settings" description="This section is under development" />}
        </div>
      </div>
    </div>
  );
}