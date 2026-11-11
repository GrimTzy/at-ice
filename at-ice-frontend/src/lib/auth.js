// Session handling shared by the login page, ProtectedRoute and every logout.
// The same five keys are written for every role, so nothing from a previous
// user's session can leak into the next one.

import { clearAllDrafts } from "./useDraft";

export const LOGIN_PATH = "/login";

// Last time the person did something, in ms. In localStorage so every open tab
// of the same sign-in shares it (see components/IdleGuard.jsx).
export const ACTIVITY_KEY = "last_activity";
// Last real mouse/key/touch input. A page left open untouched is signed out
// after OPEN_TAB_LIMIT_MS of this (see components/IdleGuard.jsx).
export const INPUT_KEY = "last_input";

const SESSION_KEYS = ["token", "role", "sub_role", "user", "must_change_password", "idle_minutes", ACTIVITY_KEY, INPUT_KEY];

// Saves the response of POST /api/login.
export function saveSession(data) {
  localStorage.setItem("token", data.token);
  // Minutes of inactivity before sign-out for this role; no value = no limit
  // (the employees on their phones: nothing about idle is stored or tracked for
  // them). The server enforces the same limit.
  if (data.idle_minutes > 0) {
    localStorage.setItem("idle_minutes", String(data.idle_minutes));
    localStorage.setItem(ACTIVITY_KEY, String(Date.now()));
    localStorage.setItem(INPUT_KEY, String(Date.now()));
  } else {
    localStorage.removeItem("idle_minutes");
    localStorage.removeItem(ACTIVITY_KEY);
    localStorage.removeItem(INPUT_KEY);
  }
  localStorage.setItem("role", data.role || data.user?.role || "");
  localStorage.setItem("sub_role", data.sub_role || "");
  localStorage.setItem("user", JSON.stringify(data.user || {}));
  if (data.must_change_password) {
    localStorage.setItem("must_change_password", "1");
  } else {
    localStorage.removeItem("must_change_password");
  }
}

export function clearSession() {
  SESSION_KEYS.forEach((k) => localStorage.removeItem(k));
  // Unsaved form drafts (lib/useDraft.js) never outlive the session.
  clearAllDrafts();
}

const HOME_PATH_BY_ROLE = {
  admin: "/dashboard",
  hr: "/hr/dashboard",
  driver: "/driver/deliveries",
  customer: "/customer/dashboard",
};

const HOME_PATH_BY_SUB_ROLE = {
  checker: "/checker/dashboard",
  salesman: "/salesman/deliveries",
  maintenance: "/maintenance/dashboard",
};

// Where a user lands after login: their own dashboard. Returns null for an
// employee with no known sub_role, or an unknown role.
export function homePathFor(role, subRole) {
  if (role === "employee") return HOME_PATH_BY_SUB_ROLE[subRole] || null;
  return HOME_PATH_BY_ROLE[role] || null;
}

export const CHANGE_PASSWORD_PATH = "/change-password";

// A new account (any role) must replace its one-time password first.
export function postLoginPath(role, subRole, mustChangePassword) {
  const home = homePathFor(role, subRole);
  if (home && mustChangePassword) return CHANGE_PASSWORD_PATH;
  return home;
}
