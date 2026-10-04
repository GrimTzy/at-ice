// Session handling shared by the login page, ProtectedRoute and every logout.
// The same five keys are written for every role, so nothing from a previous
// user's session can leak into the next one.

export const LOGIN_PATH = "/login";

const SESSION_KEYS = ["token", "role", "sub_role", "user", "must_change_password"];

// Saves the response of POST /api/login.
export function saveSession(data) {
  localStorage.setItem("token", data.token);
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

// A new customer account must replace its one-time password first.
export function postLoginPath(role, subRole, mustChangePassword) {
  if (role === "customer" && mustChangePassword) return "/customer/change-password";
  return homePathFor(role, subRole);
}
