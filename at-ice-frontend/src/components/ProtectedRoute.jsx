import { Navigate, useLocation } from "react-router-dom";

const LOGIN_PATH_BY_ROLE = {
  admin: "/admin/login",
  hr: "/hr/login",
  employee: "/salesman/login",
  driver: "/driver/login",
  customer: "/customer/login",
};

export default function ProtectedRoute({
  children,
  allowedRoles,    
  allowedSubRoles,  
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