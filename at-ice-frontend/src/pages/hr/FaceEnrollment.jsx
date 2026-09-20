import { useState, useRef, useEffect, useCallback } from "react";
import * as faceapi from "face-api.js";
import HrLayout from "../../components/HrLayout";

// Page-specific icons only — sidebar/topbar/background now come from HrLayout
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
    <HrLayout pageTitle="Face Enrollment" breadcrumb="Attendance / Face Enrollment">

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

    </HrLayout>
  );
}