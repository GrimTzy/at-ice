// src/pages/Admin/EmployeesOverviewPage.jsx
//
// Admin > Employees (view only). Distinct from HR's EmployeesPage.jsx,
// which has full create/edit/deactivate plus Payroll and Performance.
// This page is read only by design: Admin can see who's registered, but
// account creation, editing, and payroll now belong to HR.
//
// v29.7: also shows Driver accounts alongside Checker/Salesman/
// Maintenance — Driver keeps its own separate login (role:driver,
// /driver/login unchanged), but is still part of the registered workforce
// HR manages, so Admin's view should include them too.
//
// Fetches GET /api/employees-summary — a read only route added specifically
// for Admin (see routes/api.php), separate from HR's full
// Route::apiResource('employees', ...). Reuses EmployeeController@index()
// under the hood, same filtered dataset, just a different route so
// Admin's access stays view only at the backend level too, not just
// hidden in the UI.

import { useState, useEffect, useCallback } from "react";
import AdminLayout from "../../components/AdminLayout";

const API_BASE = "/api";

const SearchIcon = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>;

const SUB_ROLE_STYLE = {
  checker:     "bg-cyan-500/10 text-cyan-600 border-cyan-500/20",
  salesman:    "bg-emerald-500/10 text-emerald-600 border-emerald-500/20",
  maintenance: "bg-amber-500/10 text-amber-600 border-amber-500/20",
  driver:      "bg-violet-500/10 text-violet-600 border-violet-500/20",
};

// Driver's account uses role:"driver" with no sub_role (its login/routes
// stay separate and untouched), so its "role badge" has to be derived
// rather than read straight off user.sub_role like the other three.
function displayRole(user) {
  if (!user) return null;
  if (user.role === "driver") return "driver";
  return user.sub_role || null;
}

export default function EmployeesOverviewPage() {
  const [employees, setEmployees] = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [search, setSearch]       = useState("");

  const token = localStorage.getItem("token");
  const authHeaders = {
    "Content-Type": "application/json",
    Accept: "application/json",
    Authorization: `Bearer ${token}`,
  };

  const fetchEmployees = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(`${API_BASE}/employees-summary`, { headers: authHeaders });
      if (!res.ok) throw new Error(`Request failed (${res.status})`);
      const data = await res.json();
      setEmployees(Array.isArray(data) ? data : []);
    } catch (err) {
      setError("Couldn't load employees. Check that the backend is running.");
      console.error("fetchEmployees error:", err);
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => { fetchEmployees(); }, [fetchEmployees]);

  const filtered = employees.filter((e) => {
    const q = search.trim().toLowerCase();
    if (!q) return true;
    const haystack = `${e.first_name} ${e.last_name} ${e.position}`.toLowerCase();
    return haystack.includes(q);
  });

  return (
    <AdminLayout pageTitle="Employees" breadcrumb="Employees">
      <div className="bg-white border border-black/[0.09] rounded-md">
        <div className="flex items-center justify-between px-5 py-4 border-b border-black/[0.09]">
          <div>
            <div className="text-[15px] font-semibold text-slate-800">Employees</div>
            <div className="text-xs text-slate-400 mt-0.5">
              Registered staff. Account creation and edits are managed by HR.
            </div>
          </div>
          <div className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-50 border border-black/[0.09] rounded-md text-xs text-slate-500 w-[220px]">
            <SearchIcon />
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search employee..."
              className="bg-transparent outline-none w-full placeholder:text-slate-400"
            />
          </div>
        </div>

        {error && (
          <div className="mx-5 mt-4 px-3.5 py-2.5 bg-red-500/[0.06] border border-red-500/20 rounded-md text-xs text-red-600">
            {error}
          </div>
        )}

        <div className="overflow-x-auto">
          <table className="w-full text-[13px]">
            <thead>
              <tr className="text-left text-[10px] tracking-[0.06em] uppercase text-slate-400 border-b border-black/[0.09]">
                <th className="px-5 py-2.5 font-medium">Employee</th>
                <th className="px-5 py-2.5 font-medium">Position</th>
                <th className="px-5 py-2.5 font-medium">Role</th>
                <th className="px-5 py-2.5 font-medium">Shift</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={4} className="px-5 py-8 text-center text-slate-400 text-xs">Loading employees…</td></tr>
              ) : filtered.length === 0 ? (
                <tr><td colSpan={4} className="px-5 py-8 text-center text-slate-400 text-xs">
                  {search ? "No employees match your search." : "No employees registered yet."}
                </td></tr>
              ) : (
                filtered.map((emp) => (
                  <tr key={emp.id} className="border-b border-black/[0.06] last:border-0 hover:bg-slate-50/60">
                    <td className="px-5 py-3">
                      <div className="font-medium text-slate-800">{emp.first_name} {emp.last_name}</div>
                      <div className="text-[11px] text-slate-400">{emp.employee_id}</div>
                    </td>
                    <td className="px-5 py-3 text-slate-600">{emp.position}</td>
                    <td className="px-5 py-3">
                      {displayRole(emp.user) ? (
                        <span className={`inline-block px-2 py-0.5 rounded-full text-[11px] font-medium border ${SUB_ROLE_STYLE[displayRole(emp.user)] || "bg-slate-100 text-slate-500 border-slate-200"}`}>
                          {displayRole(emp.user).charAt(0).toUpperCase() + displayRole(emp.user).slice(1)}
                        </span>
                      ) : (
                        <span className="text-slate-300">—</span>
                      )}
                    </td>
                    <td className="px-5 py-3 text-slate-600 capitalize">{emp.shift}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </AdminLayout>
  );
}