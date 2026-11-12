import { useCallback, useEffect, useState } from "react";
import { isNativeApp } from "../lib/native";
import { AppUpdate } from "../lib/appUpdate";

// Inside the Android app only: asks the server (GET /api/app-version) which build of the app is the
// newest. If it is newer than the one installed, asks "Update now?". Update downloads the new APK
// inside the app (with progress), then opens Android's installer: the user taps Install once.
// Later hides the question until the app is opened again.
// The build number of this app is stamped in by `npm run apk` (VITE_APP_BUILD).

const MY_BUILD = Number(import.meta.env.VITE_APP_BUILD) || 0;
const ASKED_KEY = "app_update_asked_build";

const askedFor = () => {
  try { return Number(sessionStorage.getItem(ASKED_KEY)) || 0; } catch { return 0; }
};

export default function AppUpdatePrompt() {
  const [offer, setOffer] = useState(null); // { build, name, apk_url }
  const [phase, setPhase] = useState("ask"); // ask | downloading | installing | error
  const [percent, setPercent] = useState(0);
  const [message, setMessage] = useState("");

  const check = useCallback(async () => {
    if (!isNativeApp() || !MY_BUILD) return;
    try {
      const res = await fetch("/api/app-version", { headers: { Accept: "application/json" } });
      if (!res.ok) return;
      const data = await res.json();
      const build = Number(data?.build) || 0;
      if (build > MY_BUILD && build > askedFor() && typeof data.apk_url === "string" && data.apk_url.startsWith("http")) {
        setOffer({ build, name: String(data.name || ""), apk_url: data.apk_url });
        setPhase("ask");
      }
    } catch {
      /* offline or the server is down: no question, the app keeps working */
    }
  }, []);

  // when the app opens, and again each time it comes back to the front
  useEffect(() => {
    check();
    const onShow = () => { if (document.visibilityState === "visible") check(); };
    document.addEventListener("visibilitychange", onShow);
    return () => document.removeEventListener("visibilitychange", onShow);
  }, [check]);

  // progress of the download (native side sends it)
  useEffect(() => {
    if (!isNativeApp()) return undefined;
    let handle;
    AppUpdate.addListener("progress", (p) => setPercent(Number(p?.percent) || 0)).then((h) => { handle = h; });
    return () => { handle?.remove(); };
  }, []);

  const later = () => {
    if (phase === "downloading" || phase === "installing") return;
    try { sessionStorage.setItem(ASKED_KEY, String(offer?.build || 0)); } catch { /* ignore */ }
    setOffer(null);
  };

  const update = async () => {
    if (!offer?.apk_url) return;
    setMessage("");
    setPercent(0);
    setPhase("downloading");
    try {
      await AppUpdate.download({ url: offer.apk_url });
      setPhase("installing");
      await AppUpdate.install();
      // Android's installer is now open; this build is done. Keep the prompt away for this build.
      try { sessionStorage.setItem(ASKED_KEY, String(offer.build)); } catch { /* ignore */ }
      setOffer(null);
    } catch (e) {
      setPhase("error");
      setMessage(String(e?.message || "The update did not finish. Try again."));
    }
  };

  useEffect(() => {
    if (!offer) return undefined;
    const onKey = (e) => e.key === "Escape" && later();
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [offer, phase]);

  if (!offer) return null;

  const busy = phase === "downloading" || phase === "installing";

  return (
    <div className="fixed inset-0 z-[110] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-slate-950/60" onClick={later} />
      <div
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="app-update-title"
        className="relative w-full max-w-[340px] bg-white border border-black/[0.09] rounded-xl shadow-2xl p-5 font-sans"
      >
        <h2 id="app-update-title" className="text-[15px] font-semibold text-slate-800">New update available</h2>
        <p className="text-[13px] text-slate-500 mt-1">
          {phase === "downloading" && `Downloading the update${offer.name ? ` (${offer.name})` : ""}… ${percent}%`}
          {phase === "installing" && "Download complete. Tap Install on the next screen."}
          {phase === "error" && (message || "The update did not finish. Try again.")}
          {phase === "ask" && `A newer version of the AT-ICE app is ready${offer.name ? ` (${offer.name})` : ""}. Update now? Your phone will ask you to install it.`}
        </p>
        {phase === "downloading" && (
          <div className="mt-3 h-2 w-full rounded-full bg-slate-200 overflow-hidden" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
            <div className="h-full bg-cyan-600 transition-[width] duration-200" style={{ width: `${percent}%` }} />
          </div>
        )}
        <div className="flex justify-end gap-2 mt-5">
          <button
            type="button"
            onClick={later}
            disabled={busy}
            className="px-3.5 py-1.5 rounded-md border border-black/[0.09] bg-white text-[13px] text-slate-600 hover:bg-slate-50 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Later
          </button>
          <button
            type="button"
            autoFocus
            onClick={update}
            disabled={busy}
            className="px-3.5 py-1.5 rounded-md bg-cyan-600 hover:bg-cyan-700 text-[13px] font-medium text-white cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
          >
            {phase === "error" ? "Try again" : "Update"}
          </button>
        </div>
      </div>
    </div>
  );
}
