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

async function sendToken(method, token, bearer) {
  try {
    await fetch("/api/device-tokens", {
      method,
      headers: { "Content-Type": "application/json", Accept: "application/json", Authorization: `Bearer ${bearer}` },
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

    await PushNotifications.addListener("registration", (t) => {
      if (!t?.value) return;
      rememberPushToken(t.value);
      const session = localStorage.getItem("token");
      if (session) sendToken("POST", t.value, session);
    });
    await PushNotifications.addListener("registrationError", () => { started = false; });

    // Tapping a notice opens the app on this person's home page (the notice itself is also in the page's feed).
    await PushNotifications.addListener("pushNotificationActionPerformed", () => {
      const path = homePathFor(localStorage.getItem("role"), localStorage.getItem("sub_role"));
      if (path && window.location.pathname !== path) window.location.assign(path);
    });

    await PushNotifications.register();
  } catch {
    started = false; // the app works without notices
  }
}

// Called at logout (lib/auth.js clearSession): this phone stops getting the notices of this person.
export function removePushToken(bearer) {
  const token = savedPushToken();
  if (!isNativeApp() || !token || !bearer) return;
  sendToken("DELETE", token, bearer);
  forgetPushToken();
}
