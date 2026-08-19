// src/components/ProtectedRoute.jsx
//
// Wrap any route element with this to require a valid token (and optionally
// a specific role / sub_role) before rendering. Redirects to the correct
// login page if the check fails, instead of silently rendering the page shell.
//
// Reads the same localStorage keys already being set by AdminLogin.jsx,
// SalesmanLogin.jsx, DriverLogin.jsx, etc: "token", "role", "sub_role".

import { Navigate, useLocation } from "react-router-dom";

// Maps each system role to where an unauthenticated/unauthorized user
// should be sent. Add "hr" once HRLogin.jsx exists (Phase 12.5).
const LOGIN_PATH_BY_ROLE = {
  admin: "/admin/login",
  hr: "/hr/login",
  employee: "/salesman/login", // update once Checker/Maintenance logins exist
  driver: "/driver/login",
  customer: "/login",
};

export default function ProtectedRoute({
  children,
  allowedRoles,     // e.g. ["admin"]  — omit to allow any logged-in role
  allowedSubRoles,  // e.g. ["salesman"] — only relevant when role === "employee"
}) {
  const location = useLocation();

  const token = localStorage.getItem("token");
  const role = localStorage.getItem("role");
  const subRole = localStorage.getItem("sub_role");

  // Not logged in at all
  if (!token) {
    const fallbackLoginPath =
      (allowedRoles && allowedRoles[0] && LOGIN_PATH_BY_ROLE[allowedRoles[0]]) ||
      "/login";
    return <Navigate to={fallbackLoginPath} replace state={{ from: location }} />;
  }

  // Logged in, but wrong role for this route
  if (allowedRoles && !allowedRoles.includes(role)) {
    const correctLoginPath = LOGIN_PATH_BY_ROLE[role] || "/login";
    return <Navigate to={correctLoginPath} replace />;
  }

  // Logged in with correct role, but wrong sub_role (e.g. Checker trying to
  // open Salesman's dashboard)
  if (allowedSubRoles && !allowedSubRoles.includes(subRole)) {
    const correctLoginPath = LOGIN_PATH_BY_ROLE[role] || "/login";
    return <Navigate to={correctLoginPath} replace />;
  }

  return children;
}