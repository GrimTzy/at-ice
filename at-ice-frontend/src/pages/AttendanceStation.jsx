import { useState, useRef, useEffect, useCallback } from "react";
import * as faceapi from "face-api.js";
import bgImage from "../assets/iceproduct.png";
import logo from "../assets/logo.png";

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
const LogInIcon = () => (
  <svg width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
    <polyline points="10 17 15 12 10 7" />
    <line x1="15" y1="12" x2="3" y2="12" />
  </svg>
);
const LogOutIcon = () => (
  <svg width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
    <polyline points="16 17 21 12 16 7" />
    <line x1="21" y1="12" x2="9" y2="12" />
  </svg>
);

const MATCH_THRESHOLD = 0.5;
const DETECTION_INTERVAL_MS = 1500;
const RESULT_DISPLAY_MS = 3500;
// After any result, the same worker is ignored for this long in the SAME
// mode, so standing in front of the camera does not post again and again.
// Switching the toggle (Time In to Time Out) is allowed right away.
const SAME_WORKER_COOLDOWN_MS = 60_000;

// Time In is green, Time Out is orange (adviser's request).
const MODES = {
  time_in: {
    label: "Time In",
    hint: "Select this when you arrive",
    activeBtn: "bg-emerald-500 text-white border-emerald-500 shadow-lg shadow-emerald-500/30",
    idleBtn: "bg-white text-emerald-600 border-emerald-300 hover:bg-emerald-50",
    ring: "ring-emerald-500",
    pill: "bg-emerald-500/20 text-emerald-300",
    dot: "bg-emerald-400",
    text: "text-emerald-600",
  },
  time_out: {
    label: "Time Out",
    hint: "Select this when you leave",
    activeBtn: "bg-orange-500 text-white border-orange-500 shadow-lg shadow-orange-500/30",
    idleBtn: "bg-white text-orange-600 border-orange-300 hover:bg-orange-50",
    ring: "ring-orange-500",
    pill: "bg-orange-500/20 text-orange-300",
    dot: "bg-orange-400",
    text: "text-orange-600",
  },
};

export default function AttendanceStation() {
  const videoRef = useRef(null);
  const streamRef = useRef(null);
  const intervalRef = useRef(null);
  const faceMatcherRef = useRef(null);
  const busyRef = useRef(false);
  const recentlyMarkedRef = useRef(new Map());
  const modeRef = useRef("time_in");

  const [modelsLoaded, setModelsLoaded] = useState(false);
  const [descriptorsLoaded, setDescriptorsLoaded] = useState(false);
  const [cameraReady, setCameraReady] = useState(false);
  const [loadError, setLoadError] = useState(null);
  const [result, setResult] = useState(null);
  const [clock, setClock] = useState(new Date());
  const [mode, setMode] = useState("time_in");

  const changeMode = (next) => {
    modeRef.current = next; // the detection loop reads the ref, not the state
    setMode(next);
    setResult(null);
  };

  // live clock
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

  // Records attendance via the public POST /api/attendances endpoint (no
  // login, this is a kiosk). The kiosk sends the matched face id and the
  // selected mode (time_in or time_out). The server decides the date and
  // the time. Returns { type, hours } where type is time_in, time_out,
  // already_timed_in, no_time_in, completed or error.
  const recordAttendance = useCallback(async (faceDataId, type) => {
    try {
      const res = await fetch("/api/attendances", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ face_data_id: Number(faceDataId), type }),
      });
      const data = await res.json().catch(() => null);
      if (data?.type) return { type: data.type, hours: data.hours_worked };
      console.error("Attendance post failed:", res.status, data);
      return { type: "error" };
    } catch (err) {
      console.warn("Attendance post error:", err);
      return { type: "error" };
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
          const currentMode = modeRef.current;
          const cooldownKey = `${faceDataId}:${currentMode}`;
          const lastMarked = recentlyMarkedRef.current.get(cooldownKey);
          const now = Date.now();
          const info = window.__faceLookup?.[faceDataId];

          if (lastMarked && now - lastMarked < SAME_WORKER_COOLDOWN_MS) {
            // Same worker, same mode, still in front of the camera: do not post again
            busyRef.current = false;
            return;
          }

          const { type, hours } = await recordAttendance(faceDataId, currentMode);
          if (type !== "error") recentlyMarkedRef.current.set(cooldownKey, now);
          setResult({ status: type, name: info?.name, role: info?.role, hours });
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
    time_in:          { label: "Time In Recorded",    sub: "Proceed to the fingerprint scanner", bg: "bg-emerald-600/90", icon: <CheckIcon /> },
    time_out:         { label: "Time Out Recorded",   sub: "Proceed to the fingerprint scanner", bg: "bg-orange-500/90",  icon: <CheckIcon /> },
    already_timed_in: { label: "Already Timed In",    sub: "Select Time Out at the end of your shift", bg: "bg-amber-600/90", icon: <ClockIcon /> },
    no_time_in:       { label: "No Time In Found",    sub: "Select Time In first, or inform HR", bg: "bg-amber-600/90", icon: <ClockIcon /> },
    completed:        { label: "Done for Today",      sub: "Time In and Time Out are already recorded", bg: "bg-amber-600/90", icon: <ClockIcon /> },
    "no-match":       { label: "Face Not Recognized", sub: "Not enrolled in the system. Please see HR.", bg: "bg-red-600/90", icon: <XIcon /> },
    error:            { label: "Could Not Record",    sub: "Please try again or inform HR", bg: "bg-red-600/90", icon: <XIcon /> },
  };
  const active = result ? statusConfig[result.status] || statusConfig.error : null;
  const m = MODES[mode];

  return (
    <div className="relative w-full min-h-screen flex items-center justify-center overflow-hidden font-sans">

      {/* Background (same as the login page) */}
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
              <p className="text-[13px] text-slate-400 mt-1">Choose Time In or Time Out below, then face the camera.</p>
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
            <div className={`relative w-full aspect-video bg-slate-900 rounded-xl overflow-hidden ring-4 ${m.ring} transition-shadow`}>
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
                <div className={`absolute inset-0 flex flex-col items-center justify-center gap-2 backdrop-blur-sm ${active.bg}`}>
                  <div className="text-white">{active.icon}</div>
                  <span className="text-white text-xl font-semibold mt-1">{active.label}</span>
                  {result?.name && (
                    <span className="text-white/90 text-[13px]">{result.name}{result.role ? ` · ${result.role}` : ""}</span>
                  )}
                  {result?.status === "time_out" && result?.hours != null && (
                    <span className="text-white text-[13px] font-medium">Hours worked: {result.hours}</span>
                  )}
                  <span className="text-white/70 text-[11px] mt-0.5">{active.sub}</span>
                </div>
              )}

              {!result && cameraReady && !loadError && (
                <div className={`absolute top-4 left-4 flex items-center gap-1.5 px-3 py-1.5 backdrop-blur-sm rounded-full ${m.pill}`}>
                  <span className={`w-1.5 h-1.5 rounded-full animate-pulse ${m.dot}`} />
                  <span className="text-[11px] font-medium tracking-wide">Scanning for {m.label}</span>
                </div>
              )}
            </div>

            {/* Time In / Time Out toggle: Time In green, Time Out orange */}
            <div className="mt-5">
              <div className="grid grid-cols-2 gap-3">
                {Object.entries(MODES).map(([key, cfg]) => (
                  <button
                    key={key}
                    type="button"
                    onClick={() => changeMode(key)}
                    aria-pressed={mode === key}
                    className={`flex flex-col items-center justify-center gap-0.5 py-4 rounded-xl border-2 text-[17px] font-semibold cursor-pointer transition-all ${
                      mode === key ? cfg.activeBtn : cfg.idleBtn
                    }`}
                  >
                    <span className="flex items-center gap-2">
                      {key === "time_in" ? <LogInIcon /> : <LogOutIcon />}
                      {cfg.label}
                    </span>
                    <span className={`text-[11px] font-normal ${mode === key ? "text-white/80" : "text-slate-400"}`}>
                      {cfg.hint}
                    </span>
                  </button>
                ))}
              </div>
              <p className="text-center text-[12px] text-slate-500 mt-3">
                Mode selected: <span className={`font-semibold ${m.text}`}>{m.label}</span>
              </p>
            </div>

            <p className="text-center text-[11px] text-slate-400 mt-4">
              Step 1: Face recognition (this screen). Step 2: Fingerprint on the existing biometric scanner.
            </p>
          </div>
        </div>

        <p className="text-center text-[10px] text-white/30 mt-5 tracking-wide">
          © 2026 AT-ICE ERP · Attendance Station v1.1
        </p>
      </div>
    </div>
  );
}