import { useState, useEffect, useCallback } from "react";
import HrLayout from "../../components/HrLayout";
import PhoneInput from "../../components/PhoneInput";
import PasswordRules from "../../components/PasswordRules";
import { isStrongPassword, PASSWORD_RULES_MESSAGE } from "../../lib/password";
import { MOBILE_REGEX, MOBILE_MESSAGE, localDigits, toStoredPhone } from "../../lib/phone";

const API_BASE = "/api";

const SearchIcon  = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>;
const PlusIcon    = () => <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>;
const EditIcon    = () => <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z" /></svg>;
const XIcon       = () => <svg width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>;
const UserOffIcon = () => <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="8.5" cy="7" r="4" /><line x1="18" y1="8" x2="23" y2="13" /><line x1="23" y1="8" x2="18" y2="13" /></svg>;
const WalletIcon  = () => <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/></svg>;
const StarIcon    = () => <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>;

const SUB_ROLE_STYLE = {
  checker:     "bg-cyan-500/10 text-cyan-600 border-cyan-500/20",
  salesman:    "bg-emerald-500/10 text-emerald-600 border-emerald-500/20",
  maintenance: "bg-amber-500/10 text-amber-600 border-amber-500/20",
  driver:      "bg-violet-500/10 text-violet-600 border-violet-500/20",
};

// Driver's account uses role:"driver" with no sub_role (keeps the
// role:driver routes unchanged), so its "role badge" has to be derived
// rather than read straight off user.sub_role like the other three.
function displayRole(user) {
  if (!user) return null;
  if (user.role === "driver") return "driver";
  return user.sub_role || null;
}

// Philippine mobile number: 11 digits starting with 09 (same rule as EmployeeController)

// Position is fixed to the roles that get a login account. The login role is
// derived from it, so Position and Role can never disagree.
const POSITION_ROLES = {
  Checker: "checker",
  Salesman: "salesman",
  Maintenance: "maintenance",
  Driver: "driver",
};

// Today as YYYY-MM-DD in the browser's local time (toISOString would be UTC).
const todayStr = () => {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

const emptyForm = {
  first_name: "", last_name: "", email: "", password: "",
  phone: "", block_lot: "", street: "", subdivision: "", barangay: "",
  city: "", province: "", postal_code: "", position: "", department: "",
  _legacy: false, // display-only: true for an older employee with only the one-line address
  _legacyAddress: "", // display-only: old one-line address of an older employee
  date_hired: "", shift: "day",
  employment_type: "regular", sub_role: "",
  vehicle_plate: "", // Driver-only — ignored server-side for every other role
  _userRole: "", // display-only, set when editing — lets the Edit modal
                 // show "Driver" even though Driver has no sub_role
};

// ── Payroll computation (2026 PH rates, simplified/bracket-rounded) ──
function computeMSC(basicMonthly) {
  const msc = Math.round(basicMonthly / 500) * 500;
  return Math.min(Math.max(msc, 5000), 35000);
}
function computeSSS(basicMonthly) {
  const msc = computeMSC(basicMonthly);
  return +(msc * 0.05).toFixed(2); // employee share, 5%
}
function computePhilHealth(basicMonthly) {
  const base = Math.min(Math.max(basicMonthly, 10000), 100000);
  return +(base * 0.025).toFixed(2); // employee share, 2.5%
}
function computePagIbig(basicMonthly) {
  const base = Math.min(basicMonthly, 10000);
  return +Math.min(base * 0.02, 200).toFixed(2); // employee share, capped 200
}
// BIR TRAIN Law monthly withholding tax table, effective 2023 onward, still current for 2026
function computeWithholdingTax(taxableMonthly) {
  const t = taxableMonthly;
  if (t <= 20833) return 0;
  if (t <= 33333) return +((t - 20833) * 0.15).toFixed(2);
  if (t <= 66667) return +(1875 + (t - 33333) * 0.20).toFixed(2);
  if (t <= 166667) return +(8541.80 + (t - 66667) * 0.25).toFixed(2);
  if (t <= 666667) return +(33541.80 + (t - 166667) * 0.30).toFixed(2);
  return +(183541.80 + (t - 666667) * 0.35).toFixed(2);
}
// Contributions and tax are MONTHLY amounts, so they are computed on the
// employee's monthly basis (daily rate x 26 working days, 6-day work week),
// then split evenly across the two payroll cutoffs of the month.
const WORK_DAYS_PER_MONTH = 26;
const CUTOFFS_PER_MONTH = 2;
const half = (v) => +(v / CUTOFFS_PER_MONTH).toFixed(2);

function computePayroll(daysWorked, dailyRate) {
  const grossPay = +(daysWorked * dailyRate).toFixed(2);
  const monthlyBasis = +(dailyRate * WORK_DAYS_PER_MONTH).toFixed(2);

  if (grossPay <= 0 || monthlyBasis <= 0) {
    return { grossPay, monthlyBasis, sss: 0, philhealth: 0, pagibig: 0, tax: 0, netPay: grossPay };
  }

  const sssMonthly = computeSSS(monthlyBasis);
  const philhealthMonthly = computePhilHealth(monthlyBasis);
  const pagibigMonthly = computePagIbig(monthlyBasis);
  const taxableMonthly = Math.max(monthlyBasis - sssMonthly - philhealthMonthly - pagibigMonthly, 0);
  const taxMonthly = computeWithholdingTax(taxableMonthly);

  const sss = half(sssMonthly);
  const philhealth = half(philhealthMonthly);
  const pagibig = half(pagibigMonthly);
  const tax = half(taxMonthly);

  const totalDeductions = +(sss + philhealth + pagibig + tax).toFixed(2);
  const netPay = +(grossPay - totalDeductions).toFixed(2);
  return { grossPay, monthlyBasis, sss, philhealth, pagibig, tax, netPay };
}

// ── Payslip printing (computed payroll in the Payroll modal) ──
const PrintIcon = () => <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>;
const esc = (v) => String(v ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const pesoTxt = (v) => `₱${(Number(v) || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const fmtDay = (d) => {
  if (!d) return "—";
  const dt = new Date(`${String(d).slice(0, 10)}T00:00:00`);
  return Number.isNaN(dt.getTime()) ? String(d) : dt.toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" });
};

function printPayslip({ employee, periodStart, periodEnd, days, rate, preview, notes }) {
  const name = `${employee.first_name || ""} ${employee.last_name || ""}`.trim();
  const total = preview.sss + preview.philhealth + preview.pagibig + preview.tax;
  const line = (label, value, cls = "") => `<tr class="${cls}"><td>${esc(label)}</td><td class="amt">${esc(value)}</td></tr>`;

  const html = `<!doctype html>
<html><head><meta charset="utf-8"><title>Payslip - ${esc(name)} - ${esc(fmtDay(periodStart))} to ${esc(fmtDay(periodEnd))}</title>
<style>
  @page { size: A4; margin: 18mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #0f172a; font-size: 12px; margin: 0; }
  .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1E3A5F; padding-bottom: 10px; }
  .co { font-size: 16px; font-weight: 700; color: #1E3A5F; }
  .addr { color: #475569; font-size: 10.5px; margin-top: 3px; max-width: 360px; }
  .title { text-align: right; }
  .title h1 { margin: 0; font-size: 18px; letter-spacing: 2px; color: #1E3A5F; }
  .title div { color: #475569; font-size: 10.5px; margin-top: 3px; }
  .info { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; margin: 14px 0; }
  .info div span { color: #64748b; display: inline-block; width: 105px; }
  h2 { font-size: 12px; margin: 14px 0 4px; color: #1E3A5F; text-transform: uppercase; letter-spacing: 1px; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; }
  td.amt { text-align: right; font-family: "Courier New", monospace; }
  tr.total td { font-weight: 700; border-top: 1px solid #94a3b8; }
  .net { margin-top: 14px; display: flex; justify-content: space-between; align-items: center; background: #f1f5f9; border: 1px solid #cbd5e1; padding: 10px 12px; font-size: 14px; font-weight: 700; }
  .net .amt { font-family: "Courier New", monospace; font-size: 16px; }
  .note { color: #64748b; font-size: 10px; margin-top: 10px; }
  .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 48px; }
  .sign div { border-top: 1px solid #0f172a; padding-top: 4px; text-align: center; font-size: 10.5px; color: #334155; }
  .foot { margin-top: 24px; color: #94a3b8; font-size: 9.5px; text-align: center; }
</style></head>
<body>
  <div class="head">
    <div>
      <div class="co">Bella Erin Tube Ice Inc.</div>
      <div class="addr">Block 63, Lot 4 Anubing St, Amparo Subdivision, Barangay 179 Zone 16, Caloocan City North, Metro Manila</div>
    </div>
    <div class="title">
      <h1>PAYSLIP</h1>
      <div>Payroll computation</div>
    </div>
  </div>

  <div class="info">
    <div><span>Employee</span><strong>${esc(name)}</strong></div>
    <div><span>Pay Period</span>${esc(fmtDay(periodStart))} – ${esc(fmtDay(periodEnd))}</div>
    <div><span>Employee ID</span>${esc(employee.employee_id || "—")}</div>
    <div><span>Days Worked</span>${esc(days)}</div>
    <div><span>Position</span>${esc(employee.position || "—")}</div>
    <div><span>Daily Rate</span>${esc(pesoTxt(rate))}</div>
  </div>

  <h2>Earnings</h2>
  <table>
    ${line(`Basic Pay (${days} days × ${pesoTxt(rate)})`, pesoTxt(preview.grossPay))}
    ${line("Gross Pay", pesoTxt(preview.grossPay), "total")}
  </table>

  <h2>Deductions</h2>
  <table>
    ${line("SSS", pesoTxt(preview.sss))}
    ${line("PhilHealth", pesoTxt(preview.philhealth))}
    ${line("Pag-IBIG", pesoTxt(preview.pagibig))}
    ${line("Withholding Tax", pesoTxt(preview.tax))}
    ${line("Total Deductions", pesoTxt(total), "total")}
  </table>

  <div class="net"><span>NET PAY</span><span class="amt">${esc(pesoTxt(preview.netPay))}</span></div>

  <div class="note">
    Monthly basis: ${esc(pesoTxt(rate))} × ${WORK_DAYS_PER_MONTH} days = ${esc(pesoTxt(preview.monthlyBasis))}.
    SSS, PhilHealth, Pag-IBIG and withholding tax are computed on the monthly basis and half is deducted per payroll cutoff.
    ${notes ? `<br>Notes: ${esc(notes)}` : ""}
  </div>

  <div class="sign">
    <div>Prepared by: HR Staff</div>
    <div>Received by: ${esc(name)}</div>
  </div>

  <div class="foot">Printed ${esc(new Date().toLocaleString("en-PH", { month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" }))} · AT-ICE ERP</div>
</body></html>`;

  // Hidden iframe: no popup window, and the HR page itself is not affected.
  const frame = document.createElement("iframe");
  Object.assign(frame.style, { position: "fixed", right: "0", bottom: "0", width: "0", height: "0", border: "0" });
  document.body.appendChild(frame);
  const doc = frame.contentWindow.document;
  doc.open();
  doc.write(html);
  doc.close();
  const cleanup = () => setTimeout(() => frame.remove(), 1000);
  frame.contentWindow.onafterprint = cleanup;
  setTimeout(() => {
    frame.contentWindow.focus();
    frame.contentWindow.print();
    setTimeout(cleanup, 60000); // fallback if onafterprint does not fire
  }, 250);
}

// Inclusive number of calendar days between two "YYYY-MM-DD" dates
function daysInPeriod(start, end) {
  if (!start || !end) return null;
  const a = new Date(`${start}T00:00:00`);
  const b = new Date(`${end}T00:00:00`);
  if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime())) return null;
  return Math.round((b - a) / 86400000) + 1;
}

export default function EmployeesPage() {
  const [employees, setEmployees] = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [search, setSearch]       = useState("");

  const [modalOpen, setModalOpen] = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [form, setForm]           = useState(emptyForm);
  const [formErrors, setFormErrors] = useState({});
  const [saving, setSaving]       = useState(false);

  const [confirmDeactivate, setConfirmDeactivate] = useState(null);

  const [payrollFor, setPayrollFor]   = useState(null); // employee object or null
  const [performanceFor, setPerformanceFor] = useState(null); // employee object or null

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
      const res = await fetch(`${API_BASE}/employees`, { headers: authHeaders });
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
    const haystack = `${e.first_name} ${e.last_name} ${e.employee_id} ${e.position} ${e.department ?? ""}`.toLowerCase();
    return haystack.includes(q);
  });

  const openCreate = () => {
    setEditingId(null);
    setForm(emptyForm);
    setFormErrors({});
    setModalOpen(true);
  };

  const openEdit = (emp) => {
    setEditingId(emp.id);
    setForm({
      first_name: emp.first_name || "",
      last_name: emp.last_name || "",
      email: emp.user?.email || "",
      password: "",
      phone: localDigits(emp.phone),
      block_lot: emp.block_lot || "",
      street: emp.street || "",
      subdivision: emp.subdivision || "",
      barangay: emp.barangay || "",
      city: emp.city || "",
      province: emp.province || "",
      postal_code: emp.postal_code || "",
      // Older employees have only the one-line address. Show it as a reference.
      _legacy: !(emp.street || emp.barangay),
      _legacyAddress: emp.street || emp.barangay ? "" : (emp.address || ""),
      position: emp.position || "",
      department: emp.department || "",
      date_hired: emp.date_hired ? emp.date_hired.slice(0, 10) : "",
      shift: emp.shift || "day",
      employment_type: emp.employment_type || "regular",
      sub_role: emp.user?.sub_role || "",  // display-only in edit mode
      vehicle_plate: emp.vehicle_plate || "",
      _userRole: emp.user?.role || "",     // needed to detect Driver, who has no sub_role
    });
    setFormErrors({});
    setModalOpen(true);
  };

  const closeModal = () => { setModalOpen(false); setFormErrors({}); };

  const handleChange = (field) => (e) =>
    setForm((f) => ({ ...f, [field]: e.target.value }));

  // Choosing a Position also sets the login role.
  const handlePositionChange = (e) => {
    const position = e.target.value;
    setForm((f) => ({ ...f, position, sub_role: POSITION_ROLES[position] || "" }));
  };

  const isDriverForm = editingId
    ? form._userRole === "driver"
    : form.sub_role === "driver";

  // Older employees have only the one-line address and can be edited without the
  // new fields. Once any new address field is filled, all four main fields are required.
  const addressInUse = ["block_lot", "street", "subdivision", "barangay", "city", "province", "postal_code"]
    .some((f) => String(form[f] || "").trim() !== "");
  const addressRequired = !form._legacy || addressInUse;

  const handleSubmit = async (e) => {
    e.preventDefault();
    setFormErrors({});

    if (form.phone && !MOBILE_REGEX.test(form.phone)) {
      setFormErrors({ phone: [MOBILE_MESSAGE] });
      return;
    }
    if (editingId === null && !isStrongPassword(form.password)) {
      setFormErrors({ password: [PASSWORD_RULES_MESSAGE] });
      return;
    }
    if (addressRequired) {
      const missing = {};
      for (const f of ["street", "barangay", "city", "province"]) {
        if (!String(form[f] || "").trim()) missing[f] = ["Required when an address is filled in."];
      }
      if (Object.keys(missing).length) {
        setFormErrors(missing);
        return;
      }
    }
    if (form.postal_code && !/^\d{4}$/.test(form.postal_code)) {
      setFormErrors({ postal_code: ["Postal code must be 4 digits."] });
      return;
    }
    if (form.date_hired > todayStr()) {
      setFormErrors({ date_hired: ["Date hired cannot be in the future."] });
      return;
    }

    setSaving(true);

    const isCreate = editingId === null;
    const url = isCreate ? `${API_BASE}/employees` : `${API_BASE}/employees/${editingId}`;
    const method = isCreate ? "POST" : "PUT";

    const payload = isCreate
      ? {
          first_name: form.first_name, last_name: form.last_name,
          email: form.email, password: form.password,
          phone: toStoredPhone(form.phone), block_lot: form.block_lot, street: form.street,
          subdivision: form.subdivision, barangay: form.barangay, city: form.city,
          province: form.province, postal_code: form.postal_code,
          position: form.position, department: form.department,
          daily_rate: 0, date_hired: form.date_hired,
          shift: form.shift, employment_type: form.employment_type,
          sub_role: form.sub_role || null,
          vehicle_plate: form.sub_role === "driver" ? (form.vehicle_plate || null) : null,
        }
      : {
          first_name: form.first_name, last_name: form.last_name,
          phone: toStoredPhone(form.phone), block_lot: form.block_lot, street: form.street,
          subdivision: form.subdivision, barangay: form.barangay, city: form.city,
          province: form.province, postal_code: form.postal_code,
          position: form.position, department: form.department,
          shift: form.shift, employment_type: form.employment_type,
          vehicle_plate: form._userRole === "driver" ? (form.vehicle_plate || null) : null,
        };

    try {
      const res = await fetch(url, { method, headers: authHeaders, body: JSON.stringify(payload) });
      const data = await res.json().catch(() => null);

      if (res.status === 422 && data?.errors) {
        // Fields that show their own error under the input. Any other
        // field error is shown at the top so it is never silently hidden.
        const SHOWN = ["first_name", "last_name", "email", "password", "phone", "street", "barangay", "city", "province", "block_lot", "subdivision", "postal_code", "position", "sub_role", "vehicle_plate", "shift", "employment_type", "date_hired"];
        const hidden = Object.keys(data.errors).filter((k) => !SHOWN.includes(k));
        setFormErrors(hidden.length ? { ...data.errors, _general: data.errors[hidden[0]] } : data.errors);
        return;
      }
      if (!res.ok) throw new Error(data?.message || `Request failed (${res.status})`);

      await fetchEmployees();
      closeModal();
    } catch (err) {
      setFormErrors({ _general: [err.message || "Something went wrong. Please try again."] });
      console.error("save employee error:", err);
    } finally {
      setSaving(false);
    }
  };

  const handleDeactivate = async (emp) => {
    try {
      const res = await fetch(`${API_BASE}/employees/${emp.id}`, { method: "DELETE", headers: authHeaders });
      if (!res.ok) throw new Error(`Request failed (${res.status})`);
      setConfirmDeactivate(null);
      await fetchEmployees();
    } catch (err) {
      console.error("deactivate employee error:", err);
      setError("Couldn't deactivate that employee. Please try again.");
      setConfirmDeactivate(null);
    }
  };

  return (
    <HrLayout pageTitle="Employees" breadcrumb="HRIS / Employees">
      <div className="bg-white border border-black/[0.09] rounded-md">
        <div className="flex items-center justify-between px-5 py-4 border-b border-black/[0.09]">
          <div>
            <div className="text-[15px] font-semibold text-slate-800">Employees</div>
            <div className="text-xs text-slate-400 mt-0.5">
              Checker, Salesman, Maintenance, and Driver accounts.
            </div>
          </div>
          <div className="flex items-center gap-2.5">
            <div className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-50 border border-black/[0.09] rounded-md text-xs text-slate-500 w-[220px]">
              <SearchIcon />
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search employee..."
                className="bg-transparent outline-none w-full placeholder:text-slate-400"
              />
            </div>
            <button
              onClick={openCreate}
              className="flex items-center gap-1.5 px-3.5 py-1.5 bg-cyan-500 hover:bg-cyan-600 text-white text-xs font-medium rounded-md transition-colors"
            >
              <PlusIcon /> Add Employee
            </button>
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
                <th className="px-5 py-2.5 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={5} className="px-5 py-8 text-center text-slate-400 text-xs">Loading employees…</td></tr>
              ) : filtered.length === 0 ? (
                <tr><td colSpan={5} className="px-5 py-8 text-center text-slate-400 text-xs">
                  {search ? "No employees match your search." : "No employees yet — click Add Employee to create one."}
                </td></tr>
              ) : (
                filtered.map((emp) => (
                  <tr key={emp.id} className="border-b border-black/[0.06] last:border-0 hover:bg-slate-50/60">
                    <td className="px-5 py-3">
                      <div className="font-medium text-slate-800">{emp.first_name} {emp.last_name}</div>
                      <div className="text-[11px] text-slate-400">{emp.employee_id} · {emp.user?.email}</div>
                    </td>
                    <td className="px-5 py-3 text-slate-600">
                      {emp.position}
                      {emp.department && <div className="text-[11px] text-slate-400">{emp.department}</div>}
                    </td>
                    <td className="px-5 py-3">
                      {displayRole(emp.user) ? (
                        <span className={`inline-block px-2 py-0.5 rounded-full text-[11px] font-medium border ${SUB_ROLE_STYLE[displayRole(emp.user)] || "bg-slate-100 text-slate-500 border-slate-200"}`}>
                          {displayRole(emp.user).charAt(0).toUpperCase() + displayRole(emp.user).slice(1)}
                        </span>
                      ) : (
                        <span className="text-slate-300">—</span>
                      )}
                      {emp.user?.role === "driver" && emp.vehicle_plate && (
                        <div className="text-[10px] text-slate-400 mt-1 font-mono">PN: {emp.vehicle_plate}</div>
                      )}
                    </td>
                    <td className="px-5 py-3 text-slate-600 capitalize">{emp.shift}</td>
                    <td className="px-5 py-3">
                      <div className="flex items-center justify-end gap-1.5">
                        <button
                          onClick={() => setPayrollFor(emp)}
                          title="Payroll"
                          className="flex items-center gap-1 px-2.5 py-1 border border-black/[0.09] rounded-md text-[11px] text-slate-600 hover:bg-slate-50"
                        >
                          <WalletIcon /> Payroll
                        </button>
                        <button
                          onClick={() => setPerformanceFor(emp)}
                          title="Performance"
                          className="flex items-center gap-1 px-2.5 py-1 border border-black/[0.09] rounded-md text-[11px] text-slate-600 hover:bg-slate-50"
                        >
                          <StarIcon /> Performance
                        </button>
                        <button
                          onClick={() => openEdit(emp)}
                          className="flex items-center gap-1 px-2.5 py-1 border border-black/[0.09] rounded-md text-[11px] text-slate-600 hover:bg-slate-50"
                        >
                          <EditIcon /> Edit
                        </button>
                        <button
                          onClick={() => setConfirmDeactivate(emp)}
                          title="Deactivate"
                          className="flex items-center gap-1 px-2.5 py-1 border border-red-200 rounded-md text-[11px] text-red-500 hover:bg-red-50"
                        >
                          <UserOffIcon />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* ── ADD / EDIT MODAL ── */}
      {modalOpen && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-md w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between px-5 py-4 border-b border-black/[0.09]">
              <div className="text-[15px] font-semibold text-slate-800">
                {editingId ? "Edit Employee" : "Add Employee"}
              </div>
              <button onClick={closeModal} className="text-slate-400 hover:text-slate-700">
                <XIcon />
              </button>
            </div>

            <form onSubmit={handleSubmit} className="px-5 py-4 flex flex-col gap-3.5">
              {formErrors._general && (
                <div className="px-3 py-2 bg-red-500/[0.06] border border-red-500/20 rounded-md text-xs text-red-600">
                  {formErrors._general[0]}
                </div>
              )}

              <div className="grid grid-cols-2 gap-3">
                <Field label="First Name" required error={formErrors.first_name}>
                  <input required value={form.first_name} onChange={handleChange("first_name")} className={inputCls} />
                </Field>
                <Field label="Last Name" required error={formErrors.last_name}>
                  <input required value={form.last_name} onChange={handleChange("last_name")} className={inputCls} />
                </Field>
              </div>

              {!editingId && (
                <div className="grid grid-cols-2 gap-3">
                  <Field label="Email" required error={formErrors.email}>
                    <input required type="email" value={form.email} onChange={handleChange("email")} className={inputCls} />
                  </Field>
                  <Field label="Password" required error={formErrors.password}>
                    <input required type="password" minLength={8} autoComplete="new-password" value={form.password} onChange={handleChange("password")} className={inputCls} />
                    <PasswordRules password={form.password} />
                  </Field>
                </div>
              )}
              {editingId && (
                <div className="text-[11px] text-slate-400 -mt-1.5">
                  Email: {form.email || "—"} · Login credentials aren't editable here.
                </div>
              )}

              <div className="grid grid-cols-2 gap-3">
                <Field label="Phone" error={formErrors.phone}>
                  <PhoneInput
                    value={form.phone}
                    onChange={(phone) => {
                      setForm((f) => ({ ...f, phone }));
                      if (formErrors.phone) setFormErrors((fe) => ({ ...fe, phone: undefined }));
                    }}
                    className={inputCls}
                    invalid={!!form.phone && !MOBILE_REGEX.test(form.phone)}
                  />
                  {!formErrors.phone && form.phone && !MOBILE_REGEX.test(form.phone) && (
                    <span className="text-[10px] text-red-500">{MOBILE_MESSAGE}</span>
                  )}
                </Field>
                <div className="col-span-2 text-[12px] font-semibold text-slate-700 mt-1">Address</div>
                {form._legacyAddress && !addressInUse && (
                  <p className="col-span-2 -mt-1 px-3 py-2 rounded-md bg-amber-50 border border-amber-200 text-[11px] text-amber-700">
                    Saved address (old format): {form._legacyAddress}. Fill in the fields below to replace it.
                  </p>
                )}
                <Field label="Block and Lot" error={formErrors.block_lot}>
                  <input value={form.block_lot} onChange={handleChange("block_lot")} placeholder="e.g. Blk 5 Lot 12" className={inputCls} />
                </Field>
                <Field label="Street" required={addressRequired} error={formErrors.street}>
                  <input required={addressRequired} value={form.street} onChange={handleChange("street")} placeholder="e.g. Quirino Highway" className={inputCls} />
                </Field>
                <Field label="Subdivision" error={formErrors.subdivision}>
                  <input value={form.subdivision} onChange={handleChange("subdivision")} placeholder="e.g. Citrus Subdivision" className={inputCls} />
                </Field>
                <Field label="Barangay" required={addressRequired} error={formErrors.barangay}>
                  <input required={addressRequired} value={form.barangay} onChange={handleChange("barangay")} placeholder="e.g. Muzon" className={inputCls} />
                </Field>
                <Field label="City" required={addressRequired} error={formErrors.city}>
                  <input required={addressRequired} value={form.city} onChange={handleChange("city")} placeholder="e.g. San Jose del Monte" className={inputCls} />
                </Field>
                <Field label="Province" required={addressRequired} error={formErrors.province}>
                  <input required={addressRequired} value={form.province} onChange={handleChange("province")} placeholder="e.g. Bulacan" className={inputCls} />
                </Field>
                <Field label="Postal Code" error={formErrors.postal_code}>
                  <input
                    inputMode="numeric"
                    placeholder="e.g. 3023"
                    value={form.postal_code}
                    onChange={(e) => { const postal_code = e.target.value.replace(/\D/g, "").slice(0, 4); setForm((f) => ({ ...f, postal_code })); }}
                    className={inputCls}
                  />
                </Field>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <Field label="Position" required error={formErrors.position}>
                  {editingId ? (
                    <input readOnly value={form.position || "—"} className={`${inputCls} bg-slate-100 text-slate-500`} />
                  ) : (
                    <select required value={form.position} onChange={handlePositionChange} className={inputCls}>
                      <option value="">Select position</option>
                      {Object.keys(POSITION_ROLES).map((p) => (
                        <option key={p} value={p}>{p}</option>
                      ))}
                    </select>
                  )}
                </Field>
                <Field label="Department">
                  <input value={form.department} onChange={handleChange("department")} className={inputCls} />
                </Field>
              </div>

              {!editingId && (
                <Field label="Employee Role (set from Position)" error={formErrors.sub_role}>
                  <input
                    readOnly
                    value={form.sub_role ? form.sub_role.charAt(0).toUpperCase() + form.sub_role.slice(1) : "—"}
                    className={`${inputCls} bg-slate-100 text-slate-500`}
                  />
                </Field>
              )}
              {editingId && (
                <div className="text-[11px] text-slate-400 -mt-1.5">
                  Role: {(() => {
                    const r = form.sub_role || (form._userRole === "driver" ? "driver" : "");
                    return r ? r.charAt(0).toUpperCase() + r.slice(1) : "—";
                  })()} · Not editable here.
                </div>
              )}

              {isDriverForm && (
                <Field label="Vehicle Plate Number" error={formErrors.vehicle_plate}>
                  <input
                    value={form.vehicle_plate}
                    onChange={handleChange("vehicle_plate")}
                    placeholder="e.g. V7824"
                    className={inputCls}
                  />
                  <span className="text-[10px] text-slate-400 mt-0.5 block">
                    Optional. Shown to customers alongside the driver's name (e.g. "Rhalf Valencia (PN: V7824)").
                  </span>
                </Field>
              )}

              <div className="grid grid-cols-2 gap-3">
                <Field label="Shift" required error={formErrors.shift}>
                  <select required value={form.shift} onChange={handleChange("shift")} className={inputCls}>
                    <option value="day">Day</option>
                    <option value="night">Night</option>
                  </select>
                </Field>
                <Field label="Employment Type" required error={formErrors.employment_type}>
                  <select required value={form.employment_type} onChange={handleChange("employment_type")} className={inputCls}>
                    <option value="regular">Regular</option>
                    <option value="contractual">Contractual</option>
                    <option value="part_time">Part-Time</option>
                  </select>
                </Field>
              </div>

              {!editingId && (
                <Field label="Date Hired" required error={formErrors.date_hired}>
                  <input required type="date" max={todayStr()} value={form.date_hired} onChange={handleChange("date_hired")} className={inputCls} />
                </Field>
              )}

              {!editingId && (
                <div className="text-[11px] text-slate-400 -mt-1">
                  Daily rate isn't set here — use the Payroll action on this employee's row once the account exists.
                </div>
              )}

              <div className="flex justify-end gap-2 pt-2 border-t border-black/[0.09] mt-1">
                <button type="button" onClick={closeModal} className="px-3.5 py-1.5 text-xs text-slate-500 hover:text-slate-700">
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-4 py-1.5 bg-cyan-500 hover:bg-cyan-600 disabled:opacity-50 text-white text-xs font-medium rounded-md transition-colors"
                >
                  {saving ? "Saving…" : editingId ? "Save Changes" : "Create Employee"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ── DEACTIVATE CONFIRM ── */}
      {confirmDeactivate && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-md w-full max-w-sm px-5 py-5">
            <div className="text-[15px] font-semibold text-slate-800 mb-1.5">Deactivate employee?</div>
            <div className="text-xs text-slate-500 mb-4">
              {confirmDeactivate.first_name} {confirmDeactivate.last_name} will no longer appear in the active employee list. This doesn't delete their attendance or payroll history.
            </div>
            <div className="flex justify-end gap-2">
              <button onClick={() => setConfirmDeactivate(null)} className="px-3.5 py-1.5 text-xs text-slate-500 hover:text-slate-700">
                Cancel
              </button>
              <button
                onClick={() => handleDeactivate(confirmDeactivate)}
                className="px-3.5 py-1.5 bg-red-500 hover:bg-red-600 text-white text-xs font-medium rounded-md transition-colors"
              >
                Deactivate
              </button>
            </div>
          </div>
        </div>
      )}

      {payrollFor && (
        <PayrollModal employee={payrollFor} authHeaders={authHeaders} onClose={() => setPayrollFor(null)} />
      )}
      {performanceFor && (
        <PerformanceModal employee={performanceFor} authHeaders={authHeaders} onClose={() => setPerformanceFor(null)} />
      )}
    </HrLayout>
  );
}

const inputCls = "w-full px-3 py-1.5 border border-black/[0.09] rounded-md text-[13px] text-slate-800 outline-none focus:border-cyan-500/50 focus:ring-1 focus:ring-cyan-500/20";

function Field({ label, error, required, children }) {
  return (
    <label className="flex flex-col gap-1">
      <span className="text-[11px] text-slate-500">
        {label}{required && <span className="text-red-500"> *</span>}
      </span>
      {children}
      {error && <span className="text-[10px] text-red-500">{error[0]}</span>}
    </label>
  );
}

// ── PAYROLL MODAL ──
function PayrollModal({ employee, authHeaders, onClose }) {
  const [records, setRecords] = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  const [periodStart, setPeriodStart] = useState("");
  const [periodEnd, setPeriodEnd] = useState("");
  const [daysWorked, setDaysWorked] = useState("");
  const [dailyRate, setDailyRate] = useState("");
  const [notes, setNotes] = useState("");

  const fetchRecords = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch(`${API_BASE}/payrolls?employee_id=${employee.id}`, { headers: authHeaders });
      if (!res.ok) throw new Error(`Request failed (${res.status})`);
      const data = await res.json();
      setRecords(Array.isArray(data) ? data : []);
    } catch (err) {
      setError("Couldn't load payroll records.");
      console.error("fetchRecords error:", err);
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => { fetchRecords(); }, [fetchRecords]);

  const days = parseFloat(daysWorked) || 0;
  const rate = parseFloat(dailyRate) || 0;
  const preview = computePayroll(days, rate);
  const periodDays = daysInPeriod(periodStart, periodEnd);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError(null);

    if (periodStart > todayStr()) {
      setError("Period Start cannot be a future date.");
      return;
    }
    if (periodDays !== null && periodDays < 1) {
      setError("Period End must be on or after Period Start.");
      return;
    }
    if (periodDays !== null && days > periodDays) {
      setError(`Days Worked (${days}) cannot be more than the ${periodDays} calendar days in this period.`);
      return;
    }
    if (preview.netPay < 0) {
      setError("Deductions are higher than the gross pay for this period. Check the days worked and daily rate.");
      return;
    }

    setSaving(true);
    try {
      const res = await fetch(`${API_BASE}/payrolls`, {
        method: "POST",
        headers: authHeaders,
        body: JSON.stringify({
          employee_id: employee.id,
          period_start: periodStart,
          period_end: periodEnd,
          days_worked: days,
          daily_rate: rate,
          gross_pay: preview.grossPay,
          sss_deduction: preview.sss,
          philhealth_deduction: preview.philhealth,
          pagibig_deduction: preview.pagibig,
          tax_deduction: preview.tax,
          other_deductions: 0,
          net_pay: preview.netPay,
          status: "pending",
          notes: notes || null,
        }),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok) {
        const firstError = data?.errors ? Object.values(data.errors).flat()[0] : null;
        throw new Error(firstError || data?.message || `Request failed (${res.status})`);
      }

      setPeriodStart(""); setPeriodEnd(""); setDaysWorked(""); setDailyRate(""); setNotes("");
      await fetchRecords();
    } catch (err) {
      setError(err.message || "Couldn't save payroll record.");
      console.error("save payroll error:", err);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-md w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between px-5 py-4 border-b border-black/[0.09]">
          <div>
            <div className="text-[15px] font-semibold text-slate-800">Payroll</div>
            <div className="text-xs text-slate-400">{employee.first_name} {employee.last_name} · {employee.employee_id}</div>
          </div>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-700"><XIcon /></button>
        </div>

        <div className="px-5 py-4 flex flex-col gap-4">
          {error && (
            <div className="px-3 py-2 bg-red-500/[0.06] border border-red-500/20 rounded-md text-xs text-red-600">{error}</div>
          )}

          <div>
            <div className="text-xs font-medium text-slate-700 mb-2">History</div>
            {loading ? (
              <div className="text-xs text-slate-400 py-3">Loading…</div>
            ) : records.length === 0 ? (
              <div className="text-xs text-slate-400 py-3">No payroll records yet.</div>
            ) : (
              <div className="border border-black/[0.09] rounded-md overflow-hidden">
                <table className="w-full text-[12px]">
                  <thead>
                    <tr className="text-left text-[10px] uppercase text-slate-400 bg-slate-50 border-b border-black/[0.09]">
                      <th className="px-3 py-2 font-medium">Period</th>
                      <th className="px-3 py-2 font-medium text-right">Gross</th>
                      <th className="px-3 py-2 font-medium text-right">Deductions</th>
                      <th className="px-3 py-2 font-medium text-right">Net Pay</th>
                      <th className="px-3 py-2 font-medium">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {records.map((r) => {
                      const totalDed = +(r.sss_deduction) + +(r.philhealth_deduction) + +(r.pagibig_deduction) + +(r.tax_deduction) + +(r.other_deductions);
                      return (
                        <tr key={r.id} className="border-b border-black/[0.06] last:border-0">
                          <td className="px-3 py-2 text-slate-600">{r.period_start} to {r.period_end}</td>
                          <td className="px-3 py-2 text-right text-slate-700">₱{Number(r.gross_pay).toLocaleString(undefined, {minimumFractionDigits:2})}</td>
                          <td className="px-3 py-2 text-right text-red-500">₱{totalDed.toLocaleString(undefined, {minimumFractionDigits:2})}</td>
                          <td className="px-3 py-2 text-right font-medium text-slate-800">₱{Number(r.net_pay).toLocaleString(undefined, {minimumFractionDigits:2})}</td>
                          <td className="px-3 py-2 capitalize text-slate-600">{r.status}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          <form onSubmit={handleSubmit} className="border-t border-black/[0.09] pt-4 flex flex-col gap-3">
            <div className="text-xs font-medium text-slate-700">New Payroll Entry</div>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Period Start">
                <input required type="date" max={todayStr()} value={periodStart} onChange={(e) => { setPeriodStart(e.target.value); if (periodEnd && e.target.value > periodEnd) setPeriodEnd(""); }} className={inputCls} />
              </Field>
              <Field label="Period End">
                <input required type="date" min={periodStart || undefined} value={periodEnd} onChange={(e) => setPeriodEnd(e.target.value)} className={inputCls} />
              </Field>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Days Worked">
                <input required type="number" min="0" step="0.5" value={daysWorked} onChange={(e) => setDaysWorked(e.target.value)} className={inputCls} />
              </Field>
              <Field label="Daily Rate (₱)">
                <input required type="number" min="0" step="0.01" value={dailyRate} onChange={(e) => setDailyRate(e.target.value)} className={inputCls} />
              </Field>
            </div>
            <Field label="Notes (optional)">
              <input value={notes} onChange={(e) => setNotes(e.target.value)} className={inputCls} />
            </Field>

            {days > 0 && rate > 0 && (
              <div className="bg-slate-50 border border-black/[0.09] rounded-md px-3.5 py-3 text-[12px] flex flex-col gap-1">
                <div className="flex justify-between text-[11px] text-slate-400">
                  <span>Monthly basis (₱{rate.toLocaleString(undefined, {minimumFractionDigits:2})} × {WORK_DAYS_PER_MONTH} days)</span>
                  <span>₱{preview.monthlyBasis.toLocaleString(undefined, {minimumFractionDigits:2})}</span>
                </div>
                <Row label={`Gross Pay (${days} day${days === 1 ? "" : "s"} × ₱${rate.toLocaleString(undefined, {minimumFractionDigits:2})})`} value={preview.grossPay} bold />
                <Row label="SSS" value={-preview.sss} />
                <Row label="PhilHealth" value={-preview.philhealth} />
                <Row label="Pag-IBIG" value={-preview.pagibig} />
                <Row label="Withholding Tax" value={-preview.tax} />
                <div className="border-t border-black/[0.09] mt-1 pt-1">
                  <Row label="Net Pay" value={preview.netPay} bold highlight />
                </div>
                <div className="text-[10px] text-slate-400 mt-1">
                  SSS, PhilHealth, Pag-IBIG and withholding tax are computed on the monthly basis, then half is deducted per payroll cutoff (two cutoffs per month).
                </div>
              </div>
            )}

            <div className="flex items-center gap-2 pt-1">
              {days > 0 && rate > 0 && periodStart && periodEnd && (
                <button
                  type="button"
                  onClick={() => printPayslip({ employee, periodStart, periodEnd, days, rate, preview, notes })}
                  className="flex items-center gap-1.5 px-3.5 py-1.5 border border-cyan-500/40 text-cyan-600 hover:bg-cyan-50 text-xs font-medium rounded-md transition-colors"
                >
                  <PrintIcon /> Print
                </button>
              )}
              <div className="flex-1" />
              <button type="button" onClick={onClose} className="px-3.5 py-1.5 text-xs text-slate-500 hover:text-slate-700">Close</button>
              <button
                type="submit"
                disabled={saving}
                className="px-4 py-1.5 bg-cyan-500 hover:bg-cyan-600 disabled:opacity-50 text-white text-xs font-medium rounded-md transition-colors"
              >
                {saving ? "Saving…" : "Add Payroll Entry"}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
}

function Row({ label, value, bold, highlight }) {
  const isNeg = value < 0;
  return (
    <div className={`flex justify-between ${bold ? "font-medium" : ""} ${highlight ? "text-cyan-600" : "text-slate-600"}`}>
      <span>{label}</span>
      <span>{isNeg ? "-" : ""}₱{Math.abs(value).toLocaleString(undefined, {minimumFractionDigits:2})}</span>
    </div>
  );
}

// ── PERFORMANCE MODAL ──
function PerformanceModal({ employee, authHeaders, onClose }) {
  const [records, setRecords] = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  const [periodStart, setPeriodStart] = useState("");
  const [periodEnd, setPeriodEnd] = useState("");
  const [rating, setRating] = useState("5");
  const [notes, setNotes] = useState("");

  const fetchRecords = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch(`${API_BASE}/employee-performance?employee_id=${employee.id}`, { headers: authHeaders });
      if (!res.ok) throw new Error(`Request failed (${res.status})`);
      const data = await res.json();
      setRecords(Array.isArray(data) ? data : []);
    } catch (err) {
      setError("Couldn't load performance reviews.");
      console.error("fetchRecords error:", err);
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => { fetchRecords(); }, [fetchRecords]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const res = await fetch(`${API_BASE}/employee-performance`, {
        method: "POST",
        headers: authHeaders,
        body: JSON.stringify({
          employee_id: employee.id,
          review_period_start: periodStart,
          review_period_end: periodEnd,
          rating: parseInt(rating, 10),
          notes: notes || null,
        }),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok) throw new Error(data?.message || `Request failed (${res.status})`);

      setPeriodStart(""); setPeriodEnd(""); setRating("5"); setNotes("");
      await fetchRecords();
    } catch (err) {
      setError(err.message || "Couldn't save performance review.");
      console.error("save performance error:", err);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-md w-full max-w-xl max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between px-5 py-4 border-b border-black/[0.09]">
          <div>
            <div className="text-[15px] font-semibold text-slate-800">Performance</div>
            <div className="text-xs text-slate-400">{employee.first_name} {employee.last_name} · {employee.employee_id}</div>
          </div>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-700"><XIcon /></button>
        </div>

        <div className="px-5 py-4 flex flex-col gap-4">
          {error && (
            <div className="px-3 py-2 bg-red-500/[0.06] border border-red-500/20 rounded-md text-xs text-red-600">{error}</div>
          )}

          <div>
            <div className="text-xs font-medium text-slate-700 mb-2">Review History</div>
            {loading ? (
              <div className="text-xs text-slate-400 py-3">Loading…</div>
            ) : records.length === 0 ? (
              <div className="text-xs text-slate-400 py-3">No performance reviews yet.</div>
            ) : (
              <div className="flex flex-col gap-2">
                {records.map((r) => (
                  <div key={r.id} className="border border-black/[0.09] rounded-md px-3.5 py-2.5">
                    <div className="flex items-center justify-between">
                      <span className="text-[11px] text-slate-500">{r.review_period_start} to {r.review_period_end}</span>
                      <span className="flex items-center gap-0.5 text-amber-500">
                        {Array.from({ length: 5 }).map((_, i) => (
                          <span key={i} className={i < r.rating ? "opacity-100" : "opacity-20"}><StarIcon /></span>
                        ))}
                      </span>
                    </div>
                    {r.notes && <div className="text-xs text-slate-600 mt-1.5">{r.notes}</div>}
                    {r.reviewer?.name && <div className="text-[10px] text-slate-400 mt-1">Reviewed by {r.reviewer.name}</div>}
                  </div>
                ))}
              </div>
            )}
          </div>

          <form onSubmit={handleSubmit} className="border-t border-black/[0.09] pt-4 flex flex-col gap-3">
            <div className="text-xs font-medium text-slate-700">New Review</div>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Period Start">
                <input required type="date" max={todayStr()} value={periodStart} onChange={(e) => { setPeriodStart(e.target.value); if (periodEnd && e.target.value > periodEnd) setPeriodEnd(""); }} className={inputCls} />
              </Field>
              <Field label="Period End">
                <input required type="date" min={periodStart || undefined} value={periodEnd} onChange={(e) => setPeriodEnd(e.target.value)} className={inputCls} />
              </Field>
            </div>
            <Field label="Rating">
              <select value={rating} onChange={(e) => setRating(e.target.value)} className={inputCls}>
                <option value="5">5 — Excellent</option>
                <option value="4">4 — Good</option>
                <option value="3">3 — Satisfactory</option>
                <option value="2">2 — Needs Improvement</option>
                <option value="1">1 — Poor</option>
              </select>
            </Field>
            <Field label="Notes (optional)">
              <textarea rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} className={inputCls} />
            </Field>

            <div className="flex justify-end gap-2 pt-1">
              <button type="button" onClick={onClose} className="px-3.5 py-1.5 text-xs text-slate-500 hover:text-slate-700">Close</button>
              <button
                type="submit"
                disabled={saving}
                className="px-4 py-1.5 bg-cyan-500 hover:bg-cyan-600 disabled:opacity-50 text-white text-xs font-medium rounded-md transition-colors"
              >
                {saving ? "Saving…" : "Add Review"}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
}