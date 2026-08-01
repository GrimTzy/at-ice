import { useState, useRef, useEffect, useCallback } from "react";
import * as faceapi from "face-api.js";

// ─── ICONS (same style/stroke as Dashboard.jsx) ──────────────────────────────
const Bolt        = () => <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>;
const GridIcon     = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>;
const UsersIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>;
const ClockIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>;
const FileIcon     = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>;
const DollarIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>;
const ChevRight    = () => <svg width="10" height="10" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>;
const ChevDown     = () => <svg width="10" height="10" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>;
const CalIcon      = () => <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>;
const CameraIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>;
const UserPlusIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="17" y1="11" x2="23" y2="11"/></svg>;
const CheckIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>;
const AlertIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>;

const token = () => localStorage.getItem("token") || "";

const ROLES = [
  { value: "driver", label: "Driver" },
  { value: "salesman", label: "Salesman" },
  { value: "checker", label: "Checker" },
  { value: "maintenance", label: "Maintenance" },
  { value: "guard", label: "Guard" },
  { value: "bagger", label: "Bagger" },
];
const ROLES_WITH_ACCOUNT = ["driver", "salesman", "checker", "maintenance"];

// ─── NAV (HR module — mirrors Dashboard.jsx nav shape, Attendance section active) ──
const navSections = [
  { label: "Overview", items: [{ icon: <GridIcon />, label: "HR Dashboard" }] },
  {
    label: "HRIS",
    items: [
      { icon: <UsersIcon />, label: "Employees" },
      { icon: <ClockIcon />, label: "Attendance" },
      { icon: <UserPlusIcon />, label: "Face Enrollment", active: true },
      { icon: <FileIcon />, label: "Leave Requests" },
      { icon: <DollarIcon />, label: "Payroll" },
    ],
  },
];

export default function FaceEnrollment() {
  const videoRef = useRef(null);
  const streamRef = useRef(null);

  const [modelsLoaded, setModelsLoaded] = useState(false);
  const [cameraReady, setCameraReady] = useState(false);
  const [detecting, setDetecting] = useState(false);
  const [descriptor, setDescriptor] = useState(null);
  const [faceBox, setFaceBox] = useState(null);

  const [form, setForm] = useState({ worker_name: "", role: "driver", employee_id: "" });
  const [submitting, setSubmitting] = useState(false);
  const [message, setMessage] = useState(null);
  const [enrolledList, setEnrolledList] = useState([]);
  const [loadingList, setLoadingList] = useState(true);

  const today = new Date().toLocaleDateString("en-PH", { month: "long", day: "numeric", year: "numeric" });

  // ── Load face-api.js models ──
  useEffect(() => {
    (async () => {
      try {
        const MODEL_URL = "/models";
        await Promise.all([
          faceapi.nets.ssdMobilenetv1.loadFromUri(MODEL_URL),
          faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
          faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
        ]);
        setModelsLoaded(true);
      } catch (err) {
        console.error("Model load error:", err);
        setMessage({ type: "error", text: "Failed to load face recognition models. Check public/models/." });
      }
    })();
  }, []);

  // ── Start webcam ──
  useEffect(() => {
    if (!modelsLoaded) return;
    let cancelled = false;
    (async () => {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: {} });
        if (cancelled) { stream.getTracks().forEach((t) => t.stop()); return; }
        streamRef.current = stream;
        if (videoRef.current) videoRef.current.srcObject = stream;
        setCameraReady(true);
      } catch (err) {
        console.error("Camera error:", err);
        setMessage({ type: "error", text: "Could not access webcam. Check browser permissions." });
      }
    })();
    return () => {
      cancelled = true;
      if (streamRef.current) streamRef.current.getTracks().forEach((t) => t.stop());
    };
  }, [modelsLoaded]);

  // ── Fetch enrolled list ──
  const fetchEnrolled = useCallback(async () => {
    setLoadingList(true);
    try {
      const res = await fetch("/api/face-data", {
        headers: { Accept: "application/json", Authorization: `Bearer ${token()}` },
      });
      const data = await res.json();
      setEnrolledList(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error("Fetch enrolled error:", err);
    } finally {
      setLoadingList(false);
    }
  }, []);

  useEffect(() => { fetchEnrolled(); }, [fetchEnrolled]);

  const handleCapture = async () => {
    if (!videoRef.current) return;
    setDetecting(true);
    setMessage(null);
    try {
      const detection = await faceapi.detectSingleFace(videoRef.current).withFaceLandmarks().withFaceDescriptor();
      if (!detection) {
        setMessage({ type: "error", text: "No face detected. Center the worker's face and try again." });
        setDescriptor(null);
        setFaceBox(null);
        return;
      }
      setDescriptor(detection.descriptor);
      setFaceBox(detection.detection.box);
      setMessage({ type: "success", text: "Face captured. Review details and submit enrollment." });
    } catch (err) {
      console.error("Detection error:", err);
      setMessage({ type: "error", text: "Face detection failed. Try again." });
    } finally {
      setDetecting(false);
    }
  };

  const handleFormChange = (e) => setForm((p) => ({ ...p, [e.target.name]: e.target.value }));
  const requiresEmployeeId = ROLES_WITH_ACCOUNT.includes(form.role);
  const resetCapture = () => { setDescriptor(null); setFaceBox(null); };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setMessage(null);
    if (!form.worker_name.trim()) { setMessage({ type: "error", text: "Worker name is required." }); return; }
    if (!descriptor) { setMessage({ type: "error", text: "Capture the worker's face before submitting." }); return; }
    if (requiresEmployeeId && !form.employee_id) { setMessage({ type: "error", text: "Employee ID is required for this role." }); return; }

    setSubmitting(true);
    try {
      const res = await fetch("/api/face-enroll", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", Authorization: `Bearer ${token()}` },
        body: JSON.stringify({
          worker_name: form.worker_name.trim(),
          role: form.role,
          employee_id: requiresEmployeeId ? Number(form.employee_id) : null,
          descriptor: Array.from(descriptor),
        }),
      });
      const data = await res.json();
      if (!res.ok) {
        setMessage({ type: "error", text: data.message || "Enrollment failed. Please try again." });
        return;
      }
      setMessage({ type: "success", text: `${form.worker_name} enrolled successfully.` });
      setForm({ worker_name: "", role: "driver", employee_id: "" });
      resetCapture();
      fetchEnrolled();
    } catch (err) {
      console.error("Submit error:", err);
      setMessage({ type: "error", text: "Network error. Please try again." });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="flex font-sans text-[13px] leading-relaxed bg-[#0a0d12] text-slate-800 min-h-screen">

      {/* ── SIDEBAR ── */}
      <aside className="w-[220px] min-h-screen bg-white border-r border-black/[0.09] flex flex-col flex-shrink-0 sticky top-0 h-screen overflow-y-auto">
        <div className="px-[18px] pt-5 pb-4 border-b border-black/[0.09] flex items-center gap-2.5">
          <div className="w-[34px] h-[34px] bg-cyan-500/10 border border-cyan-500/20 rounded-md flex items-center justify-center text-cyan-500"><Bolt /></div>
          <div>
            <div className="text-[15px] font-semibold text-slate-800 tracking-wide">Bella Erin</div>
            <div className="text-[10px] text-slate-400 tracking-widest uppercase">AT-ICE ERP</div>
          </div>
        </div>
        <nav className="py-2.5 flex-1">
          {navSections.map((sec) => (
            <div key={sec.label}>
              <div className="px-[18px] pt-3.5 pb-1 text-[10px] tracking-[0.1em] uppercase text-slate-400 font-medium">{sec.label}</div>
              {sec.items.map((item) => (
                <div key={item.label} className={`flex items-center gap-2.5 px-[18px] py-[7px] text-[13px] cursor-pointer transition-all duration-100 border-l-2 ${item.active ? "text-cyan-500 bg-cyan-500/[0.08] border-cyan-500" : "text-slate-500 border-transparent hover:text-slate-800 hover:bg-slate-50"}`}>
                  <span className="opacity-80">{item.icon}</span>{item.label}
                </div>
              ))}
            </div>
          ))}
        </nav>
        <div className="px-[18px] py-3.5 border-t border-black/[0.09] flex items-center gap-2.5">
          <div className="w-[30px] h-[30px] rounded-full bg-cyan-500/10 border border-cyan-400/25 flex items-center justify-center text-[11px] font-semibold text-cyan-500 flex-shrink-0">HR</div>
          <div>
            <div className="text-xs font-medium text-slate-800">HR Staff</div>
            <div className="text-[10px] text-slate-400">Human Resources</div>
          </div>
          <div className="w-[7px] h-[7px] rounded-full bg-green-500 ml-auto shadow-[0_0_5px_rgba(22,163,74,0.4)]" />
        </div>
      </aside>

      {/* ── MAIN ── */}
      <div className="flex-1 min-w-0 flex flex-col overflow-hidden">

        {/* ── TOPBAR ── */}
        <header className="h-14 bg-white border-b border-black/[0.09] flex items-center px-6 gap-4 flex-shrink-0 sticky top-0 z-10">
          <div>
            <div className="text-base font-semibold text-slate-800">Face Enrollment</div>
            <div className="text-xs text-slate-400 flex items-center gap-1">Attendance <ChevRight /> <span className="text-slate-600">Face Enrollment</span></div>
          </div>
          <div className="flex-1" />
          <div className="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-black/[0.09] rounded-md text-xs text-slate-500">
            <CalIcon /> {today}
          </div>
          <div className="flex items-center gap-2.5 px-2.5 py-1.5 pl-1.5 bg-white border border-black/[0.09] rounded-md cursor-pointer hover:bg-slate-50 transition-colors">
            <div className="w-[30px] h-[30px] rounded-full bg-gradient-to-br from-cyan-500/10 to-blue-600/10 border border-cyan-500/20 flex items-center justify-center text-[11px] font-semibold text-cyan-500">HR</div>
            <div>
              <div className="text-xs font-medium text-slate-800">HR Staff</div>
              <div className="text-[10px] text-slate-400">Human Resources</div>
            </div>
            <span className="ml-1 text-slate-400"><ChevDown /></span>
          </div>
        </header>

        {/* ── CONTENT ── */}
        <main className="flex-1 overflow-y-auto p-6 pb-8 flex flex-col gap-4">

          {message && (
            <div className={`rounded-xl px-[18px] py-3 text-xs font-medium border ${
              message.type === "success" ? "bg-cyan-500/[0.06] border-cyan-200 text-cyan-600" : "bg-red-50 border-red-100 text-red-500"
            }`}>
              {message.text}
            </div>
          )}

          <div className="grid grid-cols-[1fr_380px] gap-3">

            {/* Webcam card */}
            <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
              <div className="px-[18px] py-3.5 border-b border-black/[0.09] flex items-center gap-2">
                <span className="text-cyan-500"><CameraIcon /></span>
                <div className="text-[13px] font-semibold text-slate-800">Webcam Capture</div>
              </div>
              <div className="p-[18px]">
                <div className="relative w-full aspect-video bg-slate-900 rounded-lg overflow-hidden">
                  <video ref={videoRef} autoPlay muted playsInline className="w-full h-full object-cover" />
                  {faceBox && (
                    <div
                      className="absolute border-2 border-cyan-400 rounded"
                      style={{ left: faceBox.x, top: faceBox.y, width: faceBox.width, height: faceBox.height }}
                    />
                  )}
                  {!modelsLoaded && (
                    <div className="absolute inset-0 flex items-center justify-center text-white text-xs bg-black/50">
                      Loading face recognition models...
                    </div>
                  )}
                  {modelsLoaded && !cameraReady && (
                    <div className="absolute inset-0 flex items-center justify-center text-white text-xs bg-black/50">
                      Requesting camera access...
                    </div>
                  )}
                </div>

                <div className="flex gap-2.5 mt-4">
                  <button
                    type="button"
                    onClick={handleCapture}
                    disabled={!cameraReady || detecting}
                    className="flex-1 py-2.5 bg-cyan-500 hover:bg-cyan-600 active:bg-cyan-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-[13px] font-semibold rounded-lg transition-colors shadow-lg shadow-cyan-500/20 flex items-center justify-center gap-2"
                  >
                    {detecting ? (
                      <><span className="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />Detecting...</>
                    ) : ("Capture Face")}
                  </button>
                  {descriptor && (
                    <button
                      type="button"
                      onClick={resetCapture}
                      className="px-4 py-2.5 rounded-lg border border-black/[0.12] text-slate-500 text-[13px] font-medium hover:bg-slate-50 transition-colors"
                    >
                      Retake
                    </button>
                  )}
                </div>
                <div className="flex items-center gap-1.5 mt-2.5 text-[11px] text-slate-400">
                  {descriptor ? (
                    <><span className="text-cyan-500"><CheckIcon /></span>128-point descriptor captured</>
                  ) : ("Position the worker's face clearly in frame, then capture.")}
                </div>
              </div>
            </div>

            {/* Form card */}
            <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
              <div className="px-[18px] py-3.5 border-b border-black/[0.09] flex items-center gap-2">
                <span className="text-cyan-500"><UserPlusIcon /></span>
                <div className="text-[13px] font-semibold text-slate-800">Worker Details</div>
              </div>
              <form onSubmit={handleSubmit} className="p-[18px] flex flex-col gap-4">
                <div className="flex flex-col gap-2">
                  <label className="text-[11px] font-medium text-slate-500 tracking-widest uppercase">Worker Name</label>
                  <input
                    type="text"
                    name="worker_name"
                    value={form.worker_name}
                    onChange={handleFormChange}
                    placeholder="Juan Dela Cruz"
                    className="px-4 py-3 border border-black/[0.12] rounded-xl bg-slate-50 text-[13px] text-slate-800 placeholder-slate-400 outline-none focus:border-cyan-400 focus:bg-white focus:ring-2 focus:ring-cyan-500/10 transition-all"
                  />
                </div>

                <div className="flex flex-col gap-2">
                  <label className="text-[11px] font-medium text-slate-500 tracking-widest uppercase">Role</label>
                  <select
                    name="role"
                    value={form.role}
                    onChange={handleFormChange}
                    className="px-4 py-3 border border-black/[0.12] rounded-xl bg-slate-50 text-[13px] text-slate-800 outline-none focus:border-cyan-400 focus:bg-white focus:ring-2 focus:ring-cyan-500/10 transition-all"
                  >
                    {ROLES.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                  </select>
                </div>

                <div className="flex flex-col gap-2">
                  <label className="text-[11px] font-medium text-slate-500 tracking-widest uppercase">
                    Employee ID {!requiresEmployeeId && <span className="normal-case text-slate-400">(N/A — no system account)</span>}
                  </label>
                  <input
                    type="number"
                    name="employee_id"
                    value={form.employee_id}
                    onChange={handleFormChange}
                    disabled={!requiresEmployeeId}
                    placeholder={requiresEmployeeId ? "Linked employee ID" : "Guards/Baggers have no employee_id"}
                    className="px-4 py-3 border border-black/[0.12] rounded-xl bg-slate-50 text-[13px] text-slate-800 placeholder-slate-400 outline-none focus:border-cyan-400 focus:bg-white focus:ring-2 focus:ring-cyan-500/10 transition-all disabled:bg-slate-100 disabled:text-slate-400"
                  />
                </div>

                <button
                  type="submit"
                  disabled={submitting || !descriptor}
                  className="mt-1 w-full py-3 bg-slate-900 hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed text-white text-[13px] font-semibold rounded-xl transition-colors flex items-center justify-center gap-2"
                >
                  {submitting ? (
                    <><span className="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />Enrolling...</>
                  ) : ("Enroll Worker")}
                </button>
              </form>
            </div>
          </div>

          {/* Enrolled list */}
          <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
            <div className="px-[18px] py-3.5 border-b border-black/[0.09] flex items-center justify-between">
              <div className="text-[13px] font-semibold text-slate-800">Enrolled Workers</div>
              <span className="text-[11px] text-slate-400 font-mono">{enrolledList.length} total</span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="text-left text-slate-400 border-b border-black/[0.09]">
                    <th className="px-[18px] py-2.5 font-medium">Worker Name</th>
                    <th className="px-[18px] py-2.5 font-medium">Role</th>
                    <th className="px-[18px] py-2.5 font-medium">Employee ID</th>
                  </tr>
                </thead>
                <tbody>
                  {loadingList && (
                    <tr><td colSpan={3} className="px-[18px] py-6 text-center text-slate-400">Loading...</td></tr>
                  )}
                  {!loadingList && enrolledList.map((f) => (
                    <tr key={f.id} className="border-b border-black/[0.06] last:border-b-0 hover:bg-slate-50 transition-colors">
                      <td className="px-[18px] py-2.5 text-slate-800 font-medium">{f.worker_name}</td>
                      <td className="px-[18px] py-2.5 text-slate-500 capitalize">{f.role}</td>
                      <td className="px-[18px] py-2.5 text-slate-500 font-mono">{f.employee_id ?? "—"}</td>
                    </tr>
                  ))}
                  {!loadingList && enrolledList.length === 0 && (
                    <tr><td colSpan={3} className="px-[18px] py-6 text-center text-slate-400">
                      <div className="flex items-center justify-center gap-1.5"><AlertIcon /> No workers enrolled yet.</div>
                    </td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>

        </main>
      </div>
    </div>
  );
}