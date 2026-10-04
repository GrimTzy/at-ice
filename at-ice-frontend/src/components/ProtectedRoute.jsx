import { Navigate, useLocation } from "react-router-dom";

const LOGIN_PATH_BY_ROLE = {
  admin: "/admin/login",
  hr: "/hr/login",
  employee: "/salesman/login",
  driver: "/driver/login",
  customer: "/customer/login",
};

const LOGIN_PATH_BY_SUB_ROLE = {
  checker: "/checker/login",
  salesman: "/salesman/login",
  maintenance: "/maintenance/login",
};

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

function loginPathFor(role, subRole) {
  if (role === "employee" && subRole && LOGIN_PATH_BY_SUB_ROLE[subRole]) {
    return LOGIN_PATH_BY_SUB_ROLE[subRole];
  }
  return LOGIN_PATH_BY_ROLE[role] || "/login";
}

function homePathFor(role, subRole) {
  if (role === "employee") {
    return HOME_PATH_BY_SUB_ROLE[subRole] || loginPathFor(role, subRole);
  }
  return HOME_PATH_BY_ROLE[role] || "/login";
}

export default function ProtectedRoute({
  children,
  allowedRoles,
  allowedSubRoles,
}) {
  const location = useLocation();

  const token = localStorage.getItem("token");
  const role = localStorage.getItem("role");
  const subRole = localStorage.getItem("sub_role");

  if (!token) {
    const routeRole = allowedRoles?.[0];
    const routeSubRole = allowedSubRoles?.[0];
    return (
      <Navigate
        to={loginPathFor(routeRole, routeSubRole)}
        replace
        state={{ from: location }}
      />
    );
  }

  // New customer accounts must change their one-time password first.
  // (The server blocks their API calls until they do.)
  if (
    role === "customer" &&
    localStorage.getItem("must_change_password") === "1" &&
    location.pathname !== "/customer/change-password"
  ) {
    return <Navigate to="/customer/change-password" replace />;
  }

  if (allowedRoles && !allowedRoles.includes(role)) {
    return <Navigate to={homePathFor(role, subRole)} replace />;
  }

  if (allowedSubRoles && !allowedSubRoles.includes(subRole)) {
    return <Navigate to={homePathFor(role, subRole)} replace />;
  }

  return children;
}