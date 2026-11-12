// Where the API lives. On the website the app and the API share one address, so
// calls like fetch("/api/...") go to the same host (the Vite proxy in dev, the
// web server in production) and VITE_API_BASE is not set: nothing changes.
// Inside the Android app the page is served from the phone itself, so the same
// relative calls must go to the real server: build with VITE_API_BASE set (see
// ANDROID-APK.md) and every fetch and XMLHttpRequest to /api, /storage or
// /broadcasting gets that address in front. No call site needs to change.

const BASE = String(import.meta.env.VITE_API_BASE || "").replace(/\/+$/, "");
const OWN_PATH = /^\/(api|storage|broadcasting)(\/|$|\?)/;

export const withApiBase = (url) => (BASE && typeof url === "string" && OWN_PATH.test(url) ? BASE + url : url);

export function installApiBase() {
  if (!BASE) return;
  const nativeFetch = window.fetch.bind(window);
  window.fetch = (input, init) => nativeFetch(withApiBase(input), init);

  const open = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function patchedOpen(method, url, ...rest) {
    return open.call(this, method, withApiBase(url), ...rest);
  };
}
