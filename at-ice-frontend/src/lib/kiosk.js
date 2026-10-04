// Helpers for the Attendance Station kiosk gate. The PIN itself is never checked
// here: the backend (POST /api/kiosk/unlock) does that and returns a kiosk token.

const TOKEN_KEY = "kiosk_token";

export const getKioskToken = () => localStorage.getItem(TOKEN_KEY) || "";
export const setKioskToken = (token) => localStorage.setItem(TOKEN_KEY, token);
export const clearKioskToken = () => localStorage.removeItem(TOKEN_KEY);

// Headers for the kiosk API routes (face descriptors, posting attendance, status).
export const kioskHeaders = (extra = {}) => ({
  Accept: "application/json",
  "X-Kiosk-Token": getKioskToken(),
  ...extra,
});

// Phones and tablets are not allowed to open the kiosk.
// A device is blocked when EITHER check matches: a mobile User-Agent, or a
// window narrower than 768px.
const MOBILE_UA = /Android|iPhone|iPad|iPod|BlackBerry|Opera Mini|IEMobile/i;
export const MIN_KIOSK_WIDTH = 768;

export function isBlockedDevice() {
  const mobileAgent = MOBILE_UA.test(navigator.userAgent || "");
  const narrowScreen = window.innerWidth < MIN_KIOSK_WIDTH;
  return mobileAgent || narrowScreen;
}

// Checks that the terminal has a camera. Resolves to:
//   { status: "ok" }
//   { status: "none" }                 no videoinput device found
//   { status: "error", message }       the check itself could not run
// It never rejects.
export async function checkCamera() {
  try {
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== "function") {
      return {
        status: "error",
        message: "This browser cannot list cameras. Open the page over HTTPS or on localhost, in an up to date browser.",
      };
    }
    const devices = await navigator.mediaDevices.enumerateDevices();
    const hasCamera = devices.some((d) => d.kind === "videoinput");
    return hasCamera ? { status: "ok" } : { status: "none" };
  } catch (err) {
    return {
      status: "error",
      message: err?.message || "The hardware check failed.",
    };
  }
}
