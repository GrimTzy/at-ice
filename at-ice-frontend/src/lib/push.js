import { requestNoticeRecord } from "./noticeRecord";
import { isNativeApp } from "./native";
import { homePathFor } from "./auth";

// Phone notices of the Android app (Firebase Cloud Messaging). They show on the notification bar
// even when the app is closed. Only the Android app does this: the website never asks.
//
// The phone asks for permission once, gets its address (token), and sends it to the server.
// The server then sends a notice for each event addressed to this person. Inside the open app,
// the same notices appear as the usual pop-ups (lib/live.js), so nothing is shown twice.

const CHANNEL = {
  id: "atice_alerts",
  name: "AT-ICE",
  description: "Notices for your work",
  importance: 4, // high: heads-up, with sound
  visibility: 1,
  sound: "at_ice_notification", // res/raw/at_ice_notification.mp3
  vibration: true,
};

const TOKEN_KEY = "push_token";

export function savedPushToken() {
  try { return localStorage.getItem(TOKEN_KEY) || ""; } catch { return ""; }
}

function rememberPushToken(token) {
  try { localStorage.setItem(TOKEN_KEY, token); } catch { /* ignore */ }
}

export function forgetPushToken() {
  try { localStorage.removeItem(TOKEN_KEY); } catch { /* ignore */ }
}

// The page a notice is about, for the person who gets it. Each module has one page per mobile role
// (the same pages as the menu). Anything else goes to the role's home page.
const NOTICE_PAGES = {
  deliveries: { driver: "/driver/deliveries", salesman: "/salesman/deliveries", checker: "/checker/ice-loading" },
  inventory: { checker: "/checker/inventory", maintenance: "/maintenance/stock-out-request" },
  pickups: { checker: "/checker/pickup-log" },
  projects: { maintenance: "/maintenance/projects" },
  leave: { driver: "/my-leave", checker: "/my-leave", salesman: "/my-leave", maintenance: "/my-leave" },
  "stock-out": { checker: "/checker/inventory", maintenance: "/maintenance/stock-out-request" },
  suppliers: { checker: "/checker/suppliers" },
};

export function pathForNotice(module, role, subRole) {
  const who = role === "employee" ? subRole : role;
  const page = NOTICE_PAGES[module]?.[who];
  return page || homePathFor(role, subRole);
}

// The app proves itself with its key (the server ignores a request without it).
export function appHeaders() {
  if (!isNativeApp()) return {};
  const key = import.meta.env.VITE_APP_CLIENT_KEY || "";
  return key ? { "X-AT-ICE-App": "android", "X-AT-ICE-App-Key": key } : {};
}

async function sendToken(method, token, bearer) {
  try {
    await fetch("/api/device-tokens", {
      method,
      headers: { "Content-Type": "application/json", Accept: "application/json", Authorization: `Bearer ${bearer}`, ...appHeaders() },
      body: JSON.stringify({ token }),
      keepalive: true,
    });
  } catch {
    // no network now: the next start sends it again
  }
}

// Called after sign-in and when the app opens with a session. Asks for permission only once the
// user is signed in; if they say no, nothing is sent.
let started = false;
let listening = false; // the listeners are added once per app run
export async function startPush(bearer) {
  if (!isNativeApp() || !bearer || started) return;
  started = true;
  try {
    const { PushNotifications } = await import("@capacitor/push-notifications");

    const permission = await PushNotifications.requestPermissions();
    if (permission.receive !== "granted") {
      started = false;
      return;
    }

    await PushNotifications.createChannel(CHANNEL);

    if (!listening) {
    listening = true;
    await PushNotifications.addListener("registration", (t) => {
      if (!t?.value) return;
      rememberPushToken(t.value);
      const session = localStorage.getItem("token");
      if (session) sendToken("POST", t.value, session);
    });
    await PushNotifications.addListener("registrationError", () => { started = false; });

    // Tapping a notice opens the page it is about (see pathForNotice), for this person's role.
    await PushNotifications.addListener("pushNotificationActionPerformed", (action) => {
      const data = action?.notification?.data || {};
      const role = localStorage.getItem("role");
      const subRole = localStorage.getItem("sub_role");
      const path = pathForNotice(data.module, role, subRole);
      if (data.record && path && data.module !== "memos") {
        // the page opens the record itself (lib/noticeRecord.js)
        if (window.location.pathname === path) requestNoticeRecord(data.record);
        else { sessionStorage.setItem("open_record", data.record); window.location.assign(path); }
        return;
      }
      if (data.module === "memos") {
        // the memo sheet is in the header of the home page: open it there
        const home = homePathFor(role, subRole);
        if (window.location.pathname === home) { if (data.record) sessionStorage.setItem("open_record", data.record); window.dispatchEvent(new Event("atice:open-memos")); }
        else { sessionStorage.setItem("open_memos", "1"); if (data.record) sessionStorage.setItem("open_record", data.record); if (home) window.location.assign(home); }
        return;
      }
      if (path && window.location.pathname !== path) window.location.assign(path);
    });
    }

    await PushNotifications.register();
  } catch {
    started = false; // the app works without notices
  }
}

// Called at logout (lib/auth.js clearSession): this phone stops getting the notices of this person.
export function removePushToken(bearer) {
  // the next sign-in in this same app run must register again
  started = false;
  const token = savedPushToken();
  if (!isNativeApp() || !token || !bearer) return;
  sendToken("DELETE", token, bearer);
  forgetPushToken();
}
