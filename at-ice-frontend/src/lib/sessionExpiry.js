import { LOGIN_PATH, clearSession } from "./auth";

// One global wrapper around fetch: when a request to our own API comes back 401
// (the token expired or was revoked), the session is cleared and the user is sent
// to the login page with a short message. No individual fetch call needs changing.

export const SESSION_EXPIRED_MESSAGE = "Your session expired. Please sign in again.";
export const IDLE_MESSAGE = "You were signed out because you were inactive. Please sign in again.";
const NOTICE_KEY = "session_expired_notice";

// Returns the message once, then forgets it.
export function takeSessionExpiredNotice() {
  try {
    const notice = sessionStorage.getItem(NOTICE_KEY);
    sessionStorage.removeItem(NOTICE_KEY);
    return notice || "";
  } catch {
    return "";
  }
}

// A 401 on these is not an expired session:
//   - the login request itself (a wrong password must only show an error on the form)
//   - logout
//   - the Attendance Station kiosk routes: the kiosk has its own token (X-Kiosk-Token),
//     and a kiosk failure is never a user session ending
function isIgnored(pathname, method) {
  if (pathname === "/api/login" || pathname === "/api/logout") return true;
  if (pathname.startsWith("/api/kiosk/")) return true;
  if (pathname === "/api/face-descriptors") return true;
  if (pathname === "/api/attendances" && method === "POST") return true; // HR's GET list is protected
  return false;
}

// True when the request carries the kiosk token header.
function hasKioskHeader(input, init) {
  const sources = [init?.headers, typeof input === "object" && !(input instanceof URL) ? input.headers : null];
  return sources.some((h) => {
    if (!h) return false;
    if (typeof h.has === "function") return h.has("X-Kiosk-Token");
    if (Array.isArray(h)) return h.some(([name]) => String(name).toLowerCase() === "x-kiosk-token");
    return Object.keys(h).some((name) => name.toLowerCase() === "x-kiosk-token");
  });
}

let redirecting = false;

export const isCurrentSession = (token) => token === localStorage.getItem("token");

export function assertCurrentSession(token) {
  if (!isCurrentSession(token)) {
    throw new DOMException("The session changed before the request completed.", "AbortError");
  }
}

// Reading the body can finish after fetch itself, including on a cloned response.
function guardResponse(response, token) {
  const check = () => {
    if (response.status === 401 && !localStorage.getItem("token")) return;
    assertCurrentSession(token);
  };
  for (const method of ["json", "text", "blob", "arrayBuffer", "formData", "bytes"]) {
    if (typeof response[method] !== "function") continue;
    const read = response[method].bind(response);
    response[method] = async (...args) => {
      check();
      const body = await read(...args);
      check();
      return body;
    };
  }
  const clone = response.clone.bind(response);
  response.clone = () => guardResponse(clone(), token);
  return response;
}

// Called with the address and method of a request that came back 401. Ends the
// session and goes to the login page, unless the request is one that is ignored
// (see isIgnored), carries the kiosk token, or nobody is signed in. Shared by the
// fetch wrapper below and by XMLHttpRequest uploads (lib/upload.js), which fetch
// never sees.
export function handleUnauthorized(rawUrl, method = "GET", kiosk = false, token, message = SESSION_EXPIRED_MESSAGE) {
  if (redirecting) return;
  if (token !== undefined && !isCurrentSession(token)) return;

  try {
    const url = new URL(rawUrl, window.location.origin);

    const isOurApi = url.origin === window.location.origin && url.pathname.startsWith("/api/");
    // Only when someone is signed in: nothing to expire otherwise.
    if (!isOurApi || isIgnored(url.pathname, String(method).toUpperCase()) || kiosk || !localStorage.getItem("token")) {
      return;
    }

    redirecting = true;
    clearSession();
    try {
      sessionStorage.setItem(NOTICE_KEY, message);
    } catch {
      // the redirect still happens without the message
    }
    // Already on the login page: it shows the message itself, no reload needed.
    if (window.location.pathname !== LOGIN_PATH) {
      window.location.assign(LOGIN_PATH);
    } else {
      redirecting = false;
    }
  } catch {
    // never break the original response because of this check
  }
}

// The person was idle too long (the timer in components/IdleGuard.jsx, or the
// computer slept past the limit): sign out here, tell the server, go to login.
export function signOutForIdle() {
  if (redirecting) return;
  const token = localStorage.getItem("token");
  if (token) {
    // best effort; keepalive lets it finish while the page is leaving
    fetch("/api/logout", {
      method: "POST",
      keepalive: true,
      headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
    }).catch(() => {});
  }
  redirecting = true;
  clearSession();
  try {
    sessionStorage.setItem(NOTICE_KEY, IDLE_MESSAGE);
  } catch {
    // the redirect still happens without the message
  }
  if (window.location.pathname !== LOGIN_PATH) window.location.assign(LOGIN_PATH);
  else redirecting = false;
}

export function installSessionExpiryHandler() {
  const originalFetch = window.fetch.bind(window);

  window.fetch = async (input, init) => {
    const rawUrl = typeof input === "string" ? input : input instanceof URL ? input.href : input.url;
    const url = new URL(rawUrl, window.location.origin);
    const headers = new Headers(init?.headers ?? (typeof input === "object" ? input.headers : undefined));
    const token = url.origin === window.location.origin && url.pathname.startsWith("/api/")
      ? headers.get("Authorization")?.match(/^Bearer\s+(.+)$/i)?.[1]
        ?? (hasKioskHeader(input, init) ? null : localStorage.getItem("token"))
      : null;
    const response = await originalFetch(input, init);

    if (token) assertCurrentSession(token);

    if (response.status === 401 && !redirecting) {
      try {
        const method = String(init?.method || (typeof input === "object" && input.method) || "GET").toUpperCase();
        // the server says why: signed out for being idle, or the token just ended
        const body = await response.clone().json().catch(() => null);
        const message = body?.reason === "idle" ? IDLE_MESSAGE : SESSION_EXPIRED_MESSAGE;
        handleUnauthorized(rawUrl, method, hasKioskHeader(input, init), token ?? null, message);
      } catch {
        // never break the original response because of this check
      }
    }

    return token ? guardResponse(response, token) : response;
  };
}
