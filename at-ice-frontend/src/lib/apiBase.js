// Where the API lives. On the website the app and the API share one address, so
// calls like fetch("/api/...") go to the same host (the Vite proxy in dev, the
// web server in production) and VITE_API_BASE is not set: nothing changes.
// Inside the Android app the page is served from the phone itself, so the same
// relative calls must go to the real server: build with VITE_API_BASE set (see
// ANDROID-APK.md) and every fetch and XMLHttpRequest to /api, /storage or
// /broadcasting gets that address in front. No call site needs to change.
//
// Inside the app every such call also carries the app's proof (X-AT-ICE-App and
// X-AT-ICE-App-Key). The server refuses an app session that arrives without it,
// so a copied app token does not work from a browser.

import { isNativeApp } from "./native";

const BASE = String(import.meta.env.VITE_API_BASE || "").replace(/\/+$/, "");
const OWN_PATH = /^\/(api|storage|broadcasting)(\/|$|\?)/;
const APP_KEY = import.meta.env.VITE_APP_CLIENT_KEY || "";

export const withApiBase = (url) => (BASE && typeof url === "string" && OWN_PATH.test(url) ? BASE + url : url);

const appProof = () => (APP_KEY ? { "X-AT-ICE-App": "android", "X-AT-ICE-App-Key": APP_KEY } : {});

export function installApiBase() {
  if (!BASE) return;
  const nativeFetch = window.fetch.bind(window);
  window.fetch = (input, init) => {
    const ownApi = typeof input === "string" || input instanceof URL
      ? OWN_PATH.test(typeof input === "string" ? input : input.pathname)
      : false;
    if (ownApi && isNativeApp() && APP_KEY) {
      const headers = new Headers(init?.headers);
      for (const [k, v] of Object.entries(appProof())) headers.set(k, v);
      return nativeFetch(withApiBase(input), { ...init, headers });
    }
    return nativeFetch(withApiBase(input), init);
  };

  const open = XMLHttpRequest.prototype.open;
  const send = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function patchedOpen(method, url, ...rest) {
    this.__ownApi = typeof url === "string" && OWN_PATH.test(url);
    return open.call(this, method, withApiBase(url), ...rest);
  };
  XMLHttpRequest.prototype.send = function patchedSend(body) {
    if (this.__ownApi && isNativeApp() && APP_KEY) {
      for (const [k, v] of Object.entries(appProof())) this.setRequestHeader(k, v);
    }
    return send.call(this, body);
  };
}
