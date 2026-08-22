import { useState, useRef, useEffect, useCallback } from "react";
import * as faceapi from "face-api.js";
import bgImage from "../assets/iceproduct.png";
import logo from "../assets/logo.png";

const BoltIcon = () => (
  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
  </svg>
);
const ScanIcon = () => (
  <svg width="15" height="15" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2" />
    <circle cx="12" cy="12" r="3" />
  </svg>
);
const CheckIcon = () => (
  <svg width="34" height="34" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24">
    <polyline points="20 6 9 17 4 12" />
  </svg>
);
const XIcon = () => (
  <svg width="34" height="34" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24">
    <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
  </svg>
);
const ClockIcon = () => (
  <svg width="34" height="34" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24">
    <circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" />
  </svg>
);

const MATCH_THRESHOLD = 0.5;
const DETECTION_INTERVAL_MS = 1500;
const RESULT_DISPLAY_MS = 3000;

const todayDate = () => new Date().toISOString().split("T")[0];
const nowTime = () => new Date().toTimeString().split(" ")[0];

export default function AttendanceStation() {
  const videoRef = useRef(null);
  const streamRef = useRef(null);
  const intervalRef = useRef(null);
  const faceMatcherRef = useRef(null);
  const busyRef = useRef(false);
  const recentlyMarkedRef = useRef(new Map());

  const [modelsLoaded, setModelsLoaded] = useState(false);
  const [descriptorsLoaded, setDescriptorsLoaded] = useState(false);
  const [cameraReady, setCameraReady] = useState(false);
  const [loadError, setLoadError] = useState(null);
  const [result, setResult] = useState(null);
  const [clock, setClock] = useState(new Date());

  // live clock, matches AdminLogin's understated footer style
  useEffect(() => {
    const t = setInterval(() => setClock(new Date()), 1000);
    return () => clearInterval(t);
  }, []);

  // Load models + descriptors 
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const MODEL_URL = "/models";
        await Promise.all([
          faceapi.nets.ssdMobilenetv1.loadFromUri(MODEL_URL),
          faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
          faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
        ]);
        if (cancelled) return;
        setModelsLoaded(true);

        const res = await fetch("/api/face-descriptors", { headers: { Accept: "application/json" } });
        const data = await res.json();
        if (cancelled) return;

        const labeledDescriptors = data.map(
          (entry) => new faceapi.LabeledFaceDescriptors(String(entry.id), [new Float32Array(entry.descriptor)])
        );

        if (labeledDescriptors.length === 0) {
          setLoadError("No enrolled faces found. Enroll workers first in HR > Face Enrollment.");
          return;
        }

        window.__faceLookup = Object.fromEntries(
          data.map((entry) => [String(entry.id), { name: entry.worker_name, role: entry.role }])
        );

        faceMatcherRef.current = new faceapi.FaceMatcher(labeledDescriptors, MATCH_THRESHOLD);
        setDescriptorsLoaded(true);
      } catch (err) {
        console.error("Init error:", err);
        setLoadError("Failed to load models or face descriptors.");
      }
    })();
    return () => { cancelled = true; };
  }, []);

  // Start webcam 
  useEffect(() => {
    if (!modelsLoaded || !descriptorsLoaded) return;
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
        setLoadError("Could not access webcam. Check browser permissions.");
      }
    })();
    return () => {
      cancelled = true;
      if (streamRef.current) streamRef.current.getTracks().forEach((t) => t.stop());
    };
  }, [modelsLoaded, descriptorsLoaded]);

  const recordAttendance = useCallback(async (faceDataId) => {
    try {
      const res = await fetch("/api/attendances", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          face_data_id: Number(faceDataId),
          date: todayDate(),
          time_in: nowTime(),
          status: "present",
        }),
      });
      return res.ok;
    } catch (err) {
      console.warn("Attendance post error:", err);
      return false;
    }
  }, []);

  // Detection loop
  useEffect(() => {
    if (!cameraReady || !faceMatcherRef.current) return;

    const tick = async () => {
      if (busyRef.current || !videoRef.current) return;
      busyRef.current = true;
      try {
        const detection = await faceapi.detectSingleFace(videoRef.current).withFaceLandmarks().withFaceDescriptor();
        if (!detection) { busyRef.current = false; return; }

        const bestMatch = faceMatcherRef.current.findBestMatch(detection.descriptor);

        if (bestMatch.label === "unknown") {
          setResult({ status: "no-match" });
        } else {
          const faceDataId = bestMatch.label;
          const lastMarked = recentlyMarkedRef.current.get(faceDataId);
          const now = Date.now();
          const info = window.__faceLookup?.[faceDataId];

          if (lastMarked && now - lastMarked < 60_000) {
            setResult({ status: "duplicate", name: info?.name, role: info?.role });
          } else {
            const ok = await recordAttendance(faceDataId);
            recentlyMarkedRef.current.set(faceDataId, now);
            setResult({ status: ok ? "match" : "duplicate", name: info?.name, role: info?.role });
          }
        }
        setTimeout(() => setResult(null), RESULT_DISPLAY_MS);
      } catch (err) {
        console.error("Detection tick error:", err);
      } finally {
        busyRef.current = false;
      }
    };

    intervalRef.current = setInterval(tick, DETECTION_INTERVAL_MS);
    return () => clearInterval(intervalRef.current);
  }, [cameraReady, recordAttendance]);

  const statusConfig = {
    match:     { label: "Time In Recorded",     sub: "Attendance confirmed",  cls: "text-cyan-400 border-cyan-400/40 bg-cyan-500/10", icon: <CheckIcon /> },
    duplicate: { label: "Already Recorded Today", sub: "No duplicate entry needed", cls: "text-amber-400 border-amber-400/40 bg-amber-500/10", icon: <ClockIcon /> },
    "no-match": { label: "Face Not Recognized", sub: "Not enrolled in the system", cls: "text-red-400 border-red-400/40 bg-red-500/10", icon: <XIcon /> },
  };
  const active = result ? statusConfig[result.status] : null;

  return (
    <div className="relative w-full min-h-screen flex items-center justify-center overflow-hidden font-sans">

      {/* ── Background (same as AdminLogin.jsx) ── */}
      <div className="absolute inset-0" style={{ backgroundImage: `url(${bgImage})`, backgroundSize: "cover", backgroundPosition: "center" }} />
      <div className="absolute inset-0 bg-gradient-to-br from-slate-900/80 via-slate-900/60 to-cyan-900/40" />

      <div className="relative z-10 w-full max-w-2xl mx-4">

        {/* Brand */}
        <div className="flex flex-col items-center mb-8">
          <div className="w-16 h-16 bg-white/10 border border-white/20 backdrop-blur-sm rounded-2xl flex items-center justify-center overflow-hidden shadow-lg shadow-cyan-500/10 mb-4">
          <img src={logo} alt="Bella Erin logo" className="w-full h-full object-contain p-2" />
          </div>
          <div className="text-[26px] font-semibold text-white tracking-wide leading-none">Bella Erin</div>
          <div className="text-[11px] text-cyan-400/80 tracking-[0.2em] uppercase mt-1.5">AT-ICE ERP</div>
          <div className="flex items-center gap-1.5 mt-3 px-3.5 py-1.5 bg-cyan-500/[0.15] border border-cyan-500/30 rounded-full backdrop-blur-sm">
            <span className="text-cyan-400"><ScanIcon /></span>
            <span className="text-[12px] font-medium text-cyan-300 tracking-wide">Attendance Station</span>
          </div>
        </div>

        {/* Camera card */}
        <div className="bg-white rounded-2xl shadow-2xl shadow-black/30 overflow-hidden">
          <div className="px-8 pt-7 pb-5 border-b border-black/[0.06] flex items-center justify-between">
            <div>
              <h2 className="text-[18px] font-semibold text-slate-800 leading-tight">Face Recognition Check-In</h2>
              <p className="text-[13px] text-slate-400 mt-1">Stand in front of the camera to record attendance.</p>
            </div>
            <div className="text-right flex-shrink-0">
              <div className="text-[15px] font-semibold text-slate-800 font-mono">
                {clock.toLocaleTimeString("en-PH", { hour: "2-digit", minute: "2-digit", second: "2-digit" })}
              </div>
              <div className="text-[11px] text-slate-400">
                {clock.toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" })}
              </div>
            </div>
          </div>

          <div className="px-8 py-6">
            <div className="relative w-full aspect-video bg-slate-900 rounded-xl overflow-hidden">
              <video ref={videoRef} autoPlay muted playsInline className="w-full h-full object-cover" />

              {(!modelsLoaded || !descriptorsLoaded || !cameraReady) && !loadError && (
                <div className="absolute inset-0 flex items-center justify-center bg-black/60 text-white text-[13px] gap-2.5 flex-col">
                  <span className="w-6 h-6 border-2 border-cyan-400/40 border-t-cyan-400 rounded-full animate-spin" />
                  {!modelsLoaded ? "Loading face recognition models..." : !descriptorsLoaded ? "Loading enrolled workers..." : "Starting camera..."}
                </div>
              )}

              {loadError && (
                <div className="absolute inset-0 flex items-center justify-center bg-black/70 text-red-400 text-[13px] text-center px-8">
                  {loadError}
                </div>
              )}

              {active && (
                <div className={`absolute inset-0 flex flex-col items-center justify-center gap-2 backdrop-blur-sm ${active.cls.includes("cyan") ? "bg-cyan-600/90" : active.cls.includes("amber") ? "bg-amber-600/90" : "bg-red-600/90"}`}>
                  <div className="text-white">{active.icon}</div>
                  <span className="text-white text-xl font-semibold mt-1">{active.label}</span>
                  {result?.name && (
                    <span className="text-white/90 text-[13px]">{result.name}{result.role ? ` — ${result.role}` : ""}</span>
                  )}
                  <span className="text-white/70 text-[11px] mt-0.5">{active.sub}</span>
                </div>
              )}

              {!result && cameraReady && !loadError && (
                <div className="absolute top-4 left-4 flex items-center gap-1.5 px-3 py-1.5 bg-black/50 backdrop-blur-sm rounded-full">
                  <span className="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse" />
                  <span className="text-cyan-300 text-[11px] font-medium tracking-wide">Scanning</span>
                </div>
              )}
            </div>

            <p className="text-center text-[11px] text-slate-400 mt-5">
              Face recognition only — one step. No fingerprint required.
            </p>
          </div>
        </div>

        <p className="text-center text-[10px] text-white/30 mt-5 tracking-wide">
          © 2026 AT-ICE ERP · Attendance Station v1.0
        </p>
      </div>
    </div>
  );
}