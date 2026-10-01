import { useState, useRef, useEffect, useCallback, useMemo } from "react";
import * as faceapi from "face-api.js";
import HrLayout from "../../components/HrLayout";

// Page-specific icons only — sidebar/topbar/background now come from HrLayout
const CameraIcon   = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>;
const UserPlusIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="17" y1="11" x2="23" y2="11"/></svg>;
const CheckIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>;
const AlertIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>;
const TrashIcon    = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>;

const token = () => localStorage.getItem("token") || "";
const authHeaders = (json = false) => ({
  Accept: "application/json",
  Authorization: `Bearer ${token()}`,
  ...(json ? { "Content-Type": "application/json" } : {}),
});

const ROLE_LABELS = {
  driver: "Driver", salesman: "Salesman", checker: "Checker",
  maintenance: "Maintenance", guard: "Guard", bagger: "Bagger",
};
const NO_ACCOUNT_ROLES = ["guard", "bagger"];

// Employee's real role in the system (Driver is a user role; the others
// are sub-roles of the Employee role)
const employeeRole = (e) => (e?.user?.role === "driver" ? "driver" : e?.user?.sub_role || "");
const employeeCode = (e) => e?.employee_id || e?.employee_code || (e?.id ? `#${e.id}` : "—");
const employeeName = (e) => [e?.last_name, e?.first_name].filter(Boolean).join(", ") || e?.user?.name || "Unnamed";

const EMPTY_FORM = { mode: "account", employee_id: "", worker_name: "", role: "guard" };

export default function FaceEnrollment() {
  const videoRef = useRef(null);
  const streamRef = useRef(null);

  const [modelsLoaded, setModelsLoaded] = useState(false);
  const [cameraReady, setCameraReady] = useState(false);
  const [detecting, setDetecting] = useState(false);
  const [descriptor, setDescriptor] = useState(null);
  const [faceBox, setFaceBox] = useState(null);

  const [form, setForm] = useState(EMPTY_FORM);
  const [fieldErrors, setFieldErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);
  const [message, setMessage] = useState(null);

  const [employees, setEmployees] = useState([]);
  const [enrolledList, setEnrolledList] = useState([]);
  const [loadingList, setLoadingList] = useState(true);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);
  const [reenrollingId, setReenrollingId] = useState(null);

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

  // ── Fetch enrolled list + employees ──
  const fetchEnrolled = useCallback(async () => {
    setLoadingList(true);
    try {
      const res = await fetch("/api/face-data", { headers: authHeaders() });
      const data = await res.json();
      setEnrolledList(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error("Fetch enrolled error:", err);
    } finally {
      setLoadingList(false);
    }
  }, []);

  const fetchEmployees = useCallback(async () => {
    try {
      const res = await fetch("/api/employees", { headers: authHeaders() });
      const data = await res.json();
      const list = Array.isArray(data) ? data : data?.data || [];
      // Only workers who need attendance and have a role the kiosk knows
      setEmployees(list.filter((e) => ROLE_LABELS[employeeRole(e)]));
    } catch (err) {
      console.error("Fetch employees error:", err);
    }
  }, []);

  useEffect(() => { fetchEnrolled(); fetchEmployees(); }, [fetchEnrolled, fetchEmployees]);

  const enrolledEmployeeIds = useMemo(
    () => new Set(enrolledList.map((f) => f.employee_id).filter((v) => v != null).map(String)),
    [enrolledList]
  );
  const selectedEmployee = employees.find((e) => String(e.id) === String(form.employee_id));

  // ── Capture ──
  // The video uses object-cover, so the picture on screen is scaled and
  // cropped. The detection box is converted from video pixels to screen
  // pixels so it lands on the face.
  const toScreenBox = (box) => {
    const v = videoRef.current;
    if (!v || !v.videoWidth) return null;
    const cw = v.clientWidth, ch = v.clientHeight;
    const scale = Math.max(cw / v.videoWidth, ch / v.videoHeight);
    const offX = (cw - v.videoWidth * scale) / 2;
    const offY = (ch - v.videoHeight * scale) / 2;
    return {
      left: box.x * scale + offX,
      top: box.y * scale + offY,
      width: box.width * scale,
      height: box.height * scale,
    };
  };

  const handleCapture = async () => {
    if (!videoRef.current) return;
    setDetecting(true);
    setMessage(null);
    try {
      const detection = await faceapi.detectSingleFace(videoRef.current).withFaceLandmarks().withFaceDescriptor();
      if (!detection) {
        setMessage({ type: "error", text: "No face detected. Center the worker's face, face the camera, and try again." });
        setDescriptor(null);
        setFaceBox(null);
        return;
      }
      setDescriptor(detection.descriptor);
      setFaceBox(toScreenBox(detection.detection.box));
      setMessage({ type: "success", text: "Face captured. Check that the box is on the face, then submit enrollment." });
    } catch (err) {
      console.error("Detection error:", err);
      setMessage({ type: "error", text: "Face detection failed. Try again." });
    } finally {
      setDetecting(false);
    }
  };

  const resetCapture = () => { setDescriptor(null); setFaceBox(null); };

  const setField = (name, value) => {
    setForm((p) => ({ ...p, [name]: value }));
    setFieldErrors((p) => ({ ...p, [name]: undefined }));
  };

  // ── Submit ──
  const handleSubmit = async (e) => {
    e.preventDefault();
    setMessage(null);

    const errs = {};
    let payload;
    if (form.mode === "account") {
      if (!selectedEmployee) errs.employee_id = "Select the employee.";
      else if (enrolledEmployeeIds.has(String(selectedEmployee.id))) errs.employee_id = "This employee already has an enrolled face.";
      payload = selectedEmployee && {
        worker_name: employeeName(selectedEmployee),
        role: employeeRole(selectedEmployee),
        employee_id: Number(selectedEmployee.id),
      };
    } else {
      if (!form.worker_name.trim()) errs.worker_name = "Worker name is required.";
      payload = { worker_name: form.worker_name.trim(), role: form.role, employee_id: null };
    }
    if (!descriptor) errs.capture = "Capture the worker's face first.";

    if (Object.keys(errs).length) {
      setFieldErrors(errs);
      if (errs.capture) setMessage({ type: "error", text: errs.capture });
      return;
    }

    setSubmitting(true);
    try {
      const res = await fetch("/api/face-enroll", {
        method: "POST",
        headers: authHeaders(true),
        body: JSON.stringify({ ...payload, descriptor: Array.from(descriptor) }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        // Show the server's field errors under the matching fields
        const serverErrs = {};
        Object.entries(data.errors || {}).forEach(([k, v]) => { serverErrs[k] = Array.isArray(v) ? v[0] : v; });
        if (form.mode !== "account" && serverErrs.role) serverErrs.worker_name = serverErrs.role;
        if (form.mode === "account" && serverErrs.role) serverErrs.employee_id = data.message || serverErrs.role;
        setFieldErrors(serverErrs);
        setMessage({ type: "error", text: data.message || "Enrollment failed. Please try again." });
        return;
      }
      setMessage({ type: "success", text: `${payload.worker_name} enrolled successfully.` });
      setForm((p) => ({ ...EMPTY_FORM, mode: p.mode }));
      setFieldErrors({});
      resetCapture();
      fetchEnrolled();
    } catch (err) {
      console.error("Submit error:", err);
      setMessage({ type: "error", text: "Network error. Please try again." });
    } finally {
      setSubmitting(false);
    }
  };

  // ── Delete ──
  const confirmDelete = async () => {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      const res = await fetch(`/api/face-data/${deleteTarget.id}`, { method: "DELETE", headers: authHeaders() });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || "Failed to delete enrollment.");
      setMessage({ type: "success", text: `${deleteTarget.worker_name}'s face enrollment was deleted.` });
      setDeleteTarget(null);
      fetchEnrolled();
    } catch (err) {
      setMessage({ type: "error", text: err.message });
      setDeleteTarget(null);
    } finally {
      setDeleting(false);
    }
  };

  // ── Re-enroll: replace the face of an existing enrollment with the
  // face currently captured (keeps the same worker and attendance history)
  const reenroll = async (f) => {
    if (!descriptor) {
      setMessage({ type: "error", text: `Capture ${f.worker_name}'s face first, then click Re-enroll.` });
      return;
    }
    setReenrollingId(f.id);
    setMessage(null);
    try {
      const res = await fetch(`/api/face-data/${f.id}`, {
        method: "PUT",
        headers: authHeaders(true),
        body: JSON.stringify({ descriptor: Array.from(descriptor) }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || "Failed to re-enroll.");
      setMessage({ type: "success", text: `${f.worker_name}'s face was updated.` });
      resetCapture();
      fetchEnrolled();
    } catch (err) {
      setMessage({ type: "error", text: err.message });
    } finally {
      setReenrollingId(null);
    }
  };

  const inputCls = (hasError) =>
    `px-4 py-3 border rounded-xl bg-slate-50 text-[13px] text-slate-800 placeholder-slate-400 outline-none focus:bg-white focus:ring-2 transition-all disabled:bg-slate-100 disabled:text-slate-400 ${
      hasError ? "border-red-400 focus:border-red-400 focus:ring-red-500/10" : "border-black/[0.12] focus:border-cyan-400 focus:ring-cyan-500/10"
    }`;
  const FieldError = ({ name }) =>
    fieldErrors[name] ? <div className="text-[11px] text-red-500 -mt-1">{fieldErrors[name]}</div> : null;

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
                  className="absolute border-2 border-cyan-400 rounded pointer-events-none"
                  style={{ left: faceBox.left, top: faceBox.top, width: faceBox.width, height: faceBox.height }}
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
                ) : (descriptor ? "Capture Again" : "Capture Face")}
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
                <><span className="text-cyan-500"><CheckIcon /></span>128-point descriptor captured. The box should be on the worker's face.</>
              ) : ("Position the worker's face clearly in frame, facing the camera, in good light, then capture.")}
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

            {/* Worker type */}
            <div className="grid grid-cols-2 gap-1 p-1 bg-slate-100 rounded-xl">
              {[
                { key: "account", label: "Employee with account" },
                { key: "none",    label: "Guard / Bagger" },
              ].map((m) => (
                <button
                  key={m.key}
                  type="button"
                  onClick={() => { setForm({ ...EMPTY_FORM, mode: m.key }); setFieldErrors({}); }}
                  className={`py-2 rounded-lg text-[12px] font-medium transition-colors ${
                    form.mode === m.key ? "bg-white text-slate-800 shadow-sm" : "text-slate-500 hover:text-slate-700"
                  }`}
                >
                  {m.label}
                </button>
              ))}
            </div>

            {form.mode === "account" ? (
              <>
                <div className="flex flex-col gap-2">
                  <label className="text-[11px] font-medium text-slate-500 tracking-widest uppercase">Employee</label>
                  <select
                    value={form.employee_id}
                    onChange={(e) => setField("employee_id", e.target.value)}
                    className={inputCls(!!fieldErrors.employee_id)}
                  >
                    <option value="">Select employee…</option>
                    {employees.map((emp) => {
                      const taken = enrolledEmployeeIds.has(String(emp.id));
                      return (
                        <option key={emp.id} value={emp.id} disabled={taken}>
                          {employeeName(emp)} · {employeeCode(emp)} · {ROLE_LABELS[employeeRole(emp)]}{taken ? " (already enrolled)" : ""}
                        </option>
                      );
                    })}
                  </select>
                  <FieldError name="employee_id" />
                  {employees.length === 0 && (
                    <div className="text-[11px] text-slate-400">No employees found. Add them first in Employees.</div>
                  )}
                </div>

                {selectedEmployee && (
                  <div className="rounded-xl bg-slate-50 border border-black/[0.06] px-4 py-3 text-[12px] text-slate-600 flex flex-col gap-1">
                    <div><span className="text-slate-400">Name:</span> {employeeName(selectedEmployee)}</div>
                    <div><span className="text-slate-400">Employee ID:</span> {employeeCode(selectedEmployee)}</div>
                    <div><span className="text-slate-400">Role:</span> {ROLE_LABELS[employeeRole(selectedEmployee)]}</div>
                  </div>
                )}
              </>
            ) : (
              <>
                <div className="flex flex-col gap-2">
                  <label className="text-[11px] font-medium text-slate-500 tracking-widest uppercase">Worker Name</label>
                  <input
                    type="text"
                    value={form.worker_name}
                    onChange={(e) => setField("worker_name", e.target.value)}
                    placeholder="Dela Cruz, Juan"
                    className={inputCls(!!fieldErrors.worker_name)}
                  />
                  <FieldError name="worker_name" />
                </div>
                <div className="flex flex-col gap-2">
                  <label className="text-[11px] font-medium text-slate-500 tracking-widest uppercase">Role</label>
                  <select
                    value={form.role}
                    onChange={(e) => setField("role", e.target.value)}
                    className={inputCls(false)}
                  >
                    {NO_ACCOUNT_ROLES.map((r) => <option key={r} value={r}>{ROLE_LABELS[r]}</option>)}
                  </select>
                  <div className="text-[11px] text-slate-400">Guards and Baggers have no system account, so no employee ID is linked.</div>
                </div>
              </>
            )}

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
                <th className="px-[18px] py-2.5 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loadingList && (
                <tr><td colSpan={4} className="px-[18px] py-6 text-center text-slate-400">Loading...</td></tr>
              )}
              {!loadingList && enrolledList.map((f) => (
                <tr key={f.id} className="border-b border-black/[0.06] last:border-b-0 hover:bg-slate-50 transition-colors">
                  <td className="px-[18px] py-2.5 text-slate-800 font-medium">{f.worker_name}</td>
                  <td className="px-[18px] py-2.5 text-slate-500">{ROLE_LABELS[f.role] || f.role}</td>
                  <td className="px-[18px] py-2.5 text-slate-500 font-mono">
                    {f.employee_id != null ? employeeCode(f.employee) : "No account"}
                  </td>
                  <td className="px-[18px] py-2.5 text-right whitespace-nowrap">
                    <button
                      type="button"
                      onClick={() => reenroll(f)}
                      disabled={reenrollingId === f.id}
                      title="Replace this worker's face with the one currently captured"
                      className="inline-flex items-center gap-1 px-2.5 py-1 mr-1.5 rounded-md border border-black/[0.09] text-cyan-600 hover:bg-cyan-50 text-[11px] disabled:opacity-50"
                    >
                      <CameraIcon /> {reenrollingId === f.id ? "Updating…" : "Re-enroll"}
                    </button>
                    <button
                      type="button"
                      onClick={() => setDeleteTarget(f)}
                      title="Delete enrollment"
                      className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md border border-black/[0.09] text-red-500 hover:bg-red-50 text-[11px]"
                    >
                      <TrashIcon /> Delete
                    </button>
                  </td>
                </tr>
              ))}
              {!loadingList && enrolledList.length === 0 && (
                <tr><td colSpan={4} className="px-[18px] py-6 text-center text-slate-400">
                  <div className="flex items-center justify-center gap-1.5"><AlertIcon /> No workers enrolled yet.</div>
                </td></tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Delete confirmation */}
      {deleteTarget && (
        <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50 px-4">
          <div className="bg-white rounded-xl border border-black/[0.09] w-full max-w-sm p-5">
            <h3 className="text-[14px] font-semibold text-slate-800 mb-1.5">Delete face enrollment?</h3>
            <p className="text-[12px] text-slate-500 mb-4">
              <span className="font-medium text-slate-700">{deleteTarget.worker_name}</span> will no longer be recognized at the Attendance Station until enrolled again. Workers who already have attendance records cannot be deleted; use Re-enroll instead.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setDeleteTarget(null)} className="px-3.5 py-1.5 rounded-md border border-black/[0.09] text-slate-600 text-[12px]">
                Cancel
              </button>
              <button onClick={confirmDelete} disabled={deleting} className="px-3.5 py-1.5 rounded-md bg-red-500 hover:bg-red-600 text-white text-[12px] font-medium disabled:opacity-50">
                {deleting ? "Deleting…" : "Delete"}
              </button>
            </div>
          </div>
        </div>
      )}

    </HrLayout>
  );
}