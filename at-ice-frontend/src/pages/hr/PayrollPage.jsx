import { useEffect, useState, useCallback, useMemo } from "react";
import HrLayout from "../../components/HrLayout";

const RefreshIcon = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>;
const SearchIcon  = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>;
const XIcon       = () => <svg width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>;

const token = () => localStorage.getItem("token") || "";

async function api(url, options = {}) {
  const res = await fetch(url, {
    ...options,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: `Bearer ${token()}`,
      ...(options.headers || {}),
    },
  });
  let data = null;
  try { data = await res.json(); } catch { /* empty body */ }
  if (!res.ok) {
    const firstError = data?.errors ? Object.values(data.errors).flat()[0] : null;
    throw new Error(firstError || data?.message || `Request failed (${res.status}).`);
  }
  return data;
}

const toList = (d) => (Array.isArray(d) ? d : Array.isArray(d?.data) ? d.data : []);
const num = (v) => Number(v) || 0;
const peso = (v) => `₱${num(v).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const totalDeductions = (p) =>
  num(p.sss_deduction) + num(p.philhealth_deduction) + num(p.pagibig_deduction) + num(p.tax_deduction) + num(p.other_deductions);

const STATUS_LABEL = { pending: "Pending", approved: "Approved", paid: "Paid" };
const STATUS_CLASS = {
  pending: "bg-amber-100 text-amber-600",
  approved: "bg-cyan-100 text-cyan-600",
  paid: "bg-emerald-100 text-emerald-600",
};
const NEXT_STATUS = { pending: "approved", approved: "paid" };
const NEXT_LABEL = { pending: "Approve", approved: "Mark Paid" };

const TABS = [
  { key: "all", label: "All" },
  { key: "pending", label: "Pending" },
  { key: "approved", label: "Approved" },
  { key: "paid", label: "Paid" },
];

const empName = (e) => {
  if (!e) return "—";
  const parts = [e.first_name, e.last_name].filter(Boolean).join(" ");
  return e.name || e.full_name || parts || e.user?.name || `Employee #${e.id}`;
};
const empCode = (e) => e?.employee_code || e?.employee_no || (typeof e?.employee_id === "string" ? e.employee_id : "");

// "2026-10-01" -> "Oct 1, 2026" (local date, no timezone shift)
const fmtDate = (d) => {
  if (!d) return "—";
  const dt = new Date(`${String(d).slice(0, 10)}T00:00:00`);
  return Number.isNaN(dt.getTime()) ? String(d) : dt.toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" });
};
const fmtDateTime = (d) => {
  if (!d) return "—";
  const dt = new Date(d);
  return Number.isNaN(dt.getTime()) ? String(d) : dt.toLocaleString("en-PH", { month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" });
};
const fmtPeriod = (p) => `${fmtDate(p.period_start)} – ${fmtDate(p.period_end)}`;
// preparedBy relation is serialized under "prepared_by"
const preparedByName = (p) => (p.prepared_by && typeof p.prepared_by === "object" ? p.prepared_by.name : null) || "—";

function Modal({ title, onClose, children, width = "max-w-lg" }) {
  return (
    <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" onClick={onClose}>
      <div className={`bg-white rounded-xl border border-black/[0.09] w-full ${width} max-h-[90vh] overflow-y-auto`} onClick={(e) => e.stopPropagation()}>
        <div className="px-5 py-3.5 border-b border-black/[0.09] flex items-center justify-between">
          <div className="text-[14px] font-semibold text-slate-800">{title}</div>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-700 cursor-pointer p-1"><XIcon /></button>
        </div>
        {children}
      </div>
    </div>
  );
}

function Row({ label, value, strong, indent }) {
  return (
    <div className={`flex items-center justify-between py-1.5 ${indent ? "pl-3" : ""}`}>
      <span className={strong ? "text-slate-800 font-semibold" : "text-slate-500"}>{label}</span>
      <span className={`font-mono ${strong ? "text-slate-800 font-semibold" : "text-slate-700"}`}>{value}</span>
    </div>
  );
}

export default function PayrollPage() {
  const [payrolls, setPayrolls] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [tab, setTab] = useState("all");
  const [month, setMonth] = useState(""); // "YYYY-MM" or "" for all
  const [search, setSearch] = useState("");
  const [notice, setNotice] = useState("");

  const [viewing, setViewing] = useState(null);
  const [statusTarget, setStatusTarget] = useState(null);
  const [statusSaving, setStatusSaving] = useState(false);
  const [statusError, setStatusError] = useState("");

  const fetchAll = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      setPayrolls(toList(await api("/api/payrolls")));
    } catch (e) {
      setError(e.message || "Could not load payroll records.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchAll(); }, [fetchAll]);

  useEffect(() => {
    if (!notice) return;
    const t = setTimeout(() => setNotice(""), 3500);
    return () => clearTimeout(t);
  }, [notice]);

  // Month + search filter (tabs applied after, so tab counts follow month/search)
  const baseFiltered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return payrolls.filter((p) => {
      if (month && String(p.period_start || "").slice(0, 7) !== month) return false;
      if (!q) return true;
      return (
        empName(p.employee).toLowerCase().includes(q) ||
        String(empCode(p.employee)).toLowerCase().includes(q) ||
        String(p.employee?.position || "").toLowerCase().includes(q)
      );
    });
  }, [payrolls, month, search]);

  const counts = useMemo(() => {
    const c = { all: baseFiltered.length, pending: 0, approved: 0, paid: 0 };
    baseFiltered.forEach((p) => { if (c[p.status] !== undefined) c[p.status] += 1; });
    return c;
  }, [baseFiltered]);

  const filtered = useMemo(
    () => (tab === "all" ? baseFiltered : baseFiltered.filter((p) => p.status === tab)),
    [baseFiltered, tab]
  );

  const totals = useMemo(() => {
    const t = { gross: 0, deductions: 0, net: 0 };
    filtered.forEach((p) => {
      t.gross += num(p.gross_pay);
      t.deductions += totalDeductions(p);
      t.net += num(p.net_pay);
    });
    return t;
  }, [filtered]);

  const cards = [
    { label: "Payroll Records", value: filtered.length, context: month ? "In the selected month" : "All periods" },
    { label: "Total Gross Pay", value: peso(totals.gross), context: "Before deductions" },
    { label: "Total Deductions", value: peso(totals.deductions), context: "SSS, PhilHealth, Pag-IBIG, tax, other" },
    { label: "Total Net Pay", value: peso(totals.net), context: "Take-home pay" },
  ];

  const submitStatus = async () => {
    if (!statusTarget) return;
    const next = NEXT_STATUS[statusTarget.status];
    if (!next) return;
    setStatusSaving(true);
    setStatusError("");
    try {
      await api(`/api/payrolls/${statusTarget.id}`, { method: "PUT", body: JSON.stringify({ status: next }) });
      setNotice(`Payroll of ${empName(statusTarget.employee)} marked as ${STATUS_LABEL[next]}.`);
      setStatusTarget(null);
      await fetchAll();
    } catch (err) {
      setStatusError(err.message);
    } finally {
      setStatusSaving(false);
    }
  };

  const netMismatch = (p) => Math.abs(num(p.gross_pay) - totalDeductions(p) - num(p.net_pay)) > 0.01;

  return (
    <HrLayout
      pageTitle="Payroll"
      breadcrumb="HRIS / Payroll"
      headerActions={
        <button
          onClick={fetchAll}
          className="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-black/[0.09] rounded-md text-xs text-slate-500 cursor-pointer hover:border-cyan-400 hover:text-cyan-500 transition-colors"
        >
          <RefreshIcon /> Refresh
        </button>
      }
    >
      {notice && (
        <div className="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs rounded-lg px-4 py-2.5">{notice}</div>
      )}

      {/* HOW PAYROLL WORKS */}
      <div className="bg-cyan-50 border border-cyan-200 text-cyan-800 text-[11px] rounded-lg px-4 py-2.5">
        Payroll is computed bi-weekly per employee in the <span className="font-semibold">Employees</span> page (Payroll button), with SSS, PhilHealth, Pag-IBIG and withholding tax deductions based on the Sweldong Pinoy reference tables. This page lists all saved payroll records and tracks their status.
      </div>

      {/* SUMMARY */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        {cards.map((c) => (
          <div key={c.label} className="bg-white border border-black/[0.09] rounded-xl p-4 flex flex-col gap-1.5">
            <span className="text-[11px] font-medium tracking-[0.02em] text-slate-400">{c.label}</span>
            <div className="text-[22px] font-semibold font-mono tracking-tight leading-none text-slate-800">
              {loading ? "…" : error ? "—" : c.value}
            </div>
            <div className="text-[11px] text-slate-400">{c.context}</div>
          </div>
        ))}
      </div>

      <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
        {/* HEADER + FILTERS */}
        <div className="px-[18px] py-3.5 border-b border-black/[0.09] flex flex-wrap items-center gap-3">
          <div>
            <div className="text-[13px] font-semibold text-slate-800">Payroll Records</div>
            <div className="text-[11px] text-slate-400 mt-0.5">Pending, then Approved, then Paid.</div>
          </div>
          <div className="flex-1" />
          <div className="flex items-center gap-2">
            <label htmlFor="pay-month" className="text-[11px] font-medium text-slate-400">Period month</label>
            <input
              id="pay-month"
              type="month"
              value={month}
              onChange={(e) => setMonth(e.target.value)}
              className="px-2.5 py-1.5 border border-black/[0.09] rounded-md text-xs text-slate-700 focus:outline-none focus:border-cyan-400"
            />
            {month && (
              <button
                onClick={() => setMonth("")}
                className="px-2.5 py-1.5 border border-black/[0.09] rounded-md text-xs text-slate-500 cursor-pointer hover:border-cyan-400 hover:text-cyan-500"
              >
                All
              </button>
            )}
          </div>
          <div className="flex items-center gap-2 px-2.5 py-1.5 border border-black/[0.09] rounded-md text-slate-400 w-full sm:w-56">
            <SearchIcon />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search employee"
              className="flex-1 min-w-0 text-xs text-slate-700 bg-transparent focus:outline-none"
            />
          </div>
        </div>

        {/* TABS */}
        <div className="px-[18px] pt-3 flex flex-wrap gap-2">
          {TABS.map((t) => (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={`px-3 py-1 rounded-full text-[11px] font-medium border cursor-pointer transition-colors ${tab === t.key ? "bg-cyan-500 border-cyan-500 text-white" : "bg-white border-black/[0.09] text-slate-500 hover:border-cyan-400 hover:text-cyan-500"}`}
            >
              {t.label} ({loading ? "…" : counts[t.key]})
            </button>
          ))}
        </div>

        {/* TABLE */}
        <div className="mt-3 border-t border-black/[0.09]">
          {loading ? (
            <div className="px-[18px] py-8 text-center text-[12px] text-slate-400">Loading payroll records…</div>
          ) : error ? (
            <div className="px-[18px] py-8 text-center text-[12px] text-red-600">{error}</div>
          ) : payrolls.length === 0 ? (
            <div className="px-[18px] py-8 text-center text-[12px] text-slate-400">No payroll records yet. Create one from the Employees page using the Payroll button.</div>
          ) : filtered.length === 0 ? (
            <div className="px-[18px] py-8 text-center text-[12px] text-slate-400">No payroll records match this filter.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="text-left text-slate-400 border-b border-black/[0.09]">
                    <th className="px-[18px] py-2.5 font-medium">Employee</th>
                    <th className="px-[18px] py-2.5 font-medium">Pay Period</th>
                    <th className="px-[18px] py-2.5 font-medium text-right">Days</th>
                    <th className="px-[18px] py-2.5 font-medium text-right">Gross Pay</th>
                    <th className="px-[18px] py-2.5 font-medium text-right">Deductions</th>
                    <th className="px-[18px] py-2.5 font-medium text-right">Net Pay</th>
                    <th className="px-[18px] py-2.5 font-medium">Status</th>
                    <th className="px-[18px] py-2.5 font-medium text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((p) => (
                    <tr key={p.id} className="border-b border-black/[0.06] last:border-b-0 hover:bg-slate-50">
                      <td className="px-[18px] py-2.5">
                        <div className="text-slate-800 font-medium whitespace-nowrap">{empName(p.employee)}</div>
                        <div className="text-[10px] text-slate-400">{[empCode(p.employee), p.employee?.position].filter(Boolean).join(" · ")}</div>
                      </td>
                      <td className="px-[18px] py-2.5 text-slate-600 whitespace-nowrap">{fmtPeriod(p)}</td>
                      <td className="px-[18px] py-2.5 text-slate-600 font-mono text-right">{p.days_worked ?? "—"}</td>
                      <td className="px-[18px] py-2.5 text-slate-700 font-mono text-right whitespace-nowrap">{peso(p.gross_pay)}</td>
                      <td className="px-[18px] py-2.5 text-red-600 font-mono text-right whitespace-nowrap">−{peso(totalDeductions(p))}</td>
                      <td className="px-[18px] py-2.5 text-slate-800 font-mono font-semibold text-right whitespace-nowrap">{peso(p.net_pay)}</td>
                      <td className="px-[18px] py-2.5">
                        <span className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold ${STATUS_CLASS[p.status] || "bg-slate-100 text-slate-500"}`}>
                          {STATUS_LABEL[p.status] || p.status}
                        </span>
                      </td>
                      <td className="px-[18px] py-2.5">
                        <div className="flex items-center justify-end gap-1.5">
                          <button onClick={() => setViewing(p)} className="px-2.5 py-1 border border-black/[0.09] rounded-md text-[11px] text-slate-600 cursor-pointer hover:border-cyan-400 hover:text-cyan-500">View</button>
                          {NEXT_STATUS[p.status] && (
                            <button
                              onClick={() => { setStatusTarget(p); setStatusError(""); }}
                              className={`px-2.5 py-1 border rounded-md text-[11px] cursor-pointer whitespace-nowrap ${p.status === "pending" ? "border-cyan-200 text-cyan-600 hover:bg-cyan-50" : "border-emerald-200 text-emerald-600 hover:bg-emerald-50"}`}
                            >
                              {NEXT_LABEL[p.status]}
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {/* STATUS CHANGE MODAL */}
      {statusTarget && (
        <Modal
          title={statusTarget.status === "pending" ? "Approve Payroll" : "Mark Payroll as Paid"}
          onClose={() => !statusSaving && setStatusTarget(null)}
          width="max-w-md"
        >
          <div className="p-5 flex flex-col gap-3.5">
            <div className="bg-slate-50 border border-black/[0.06] rounded-lg p-3 text-xs flex flex-col gap-1">
              <div className="text-slate-800 font-semibold">{empName(statusTarget.employee)}</div>
              <div className="text-slate-500">{fmtPeriod(statusTarget)}</div>
              <div className="text-slate-700 mt-1">Net pay: <span className="font-mono font-semibold">{peso(statusTarget.net_pay)}</span></div>
            </div>
            <div className="text-xs text-slate-600">
              {statusTarget.status === "pending"
                ? "Approve this payroll for release?"
                : "Confirm that this salary has been released to the employee?"}
            </div>
            {statusError && <div className="text-[12px] text-red-600">{statusError}</div>}
            <div className="flex justify-end gap-2">
              <button onClick={() => setStatusTarget(null)} disabled={statusSaving} className="px-3.5 py-2 border border-black/[0.09] rounded-md text-xs text-slate-600 cursor-pointer hover:bg-slate-50 disabled:opacity-50">Close</button>
              <button
                onClick={submitStatus}
                disabled={statusSaving}
                className={`px-3.5 py-2 text-white text-xs font-semibold rounded-md cursor-pointer disabled:opacity-50 ${statusTarget.status === "pending" ? "bg-cyan-500 hover:bg-cyan-600" : "bg-emerald-500 hover:bg-emerald-600"}`}
              >
                {statusSaving ? "Saving…" : NEXT_LABEL[statusTarget.status]}
              </button>
            </div>
          </div>
        </Modal>
      )}

      {/* PAYSLIP DETAIL MODAL */}
      {viewing && (
        <Modal title="Payroll Details" onClose={() => setViewing(null)}>
          <div className="p-5 flex flex-col gap-3 text-xs">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="text-[14px] font-semibold text-slate-800">{empName(viewing.employee)}</div>
                <div className="text-slate-400">{[empCode(viewing.employee), viewing.employee?.position].filter(Boolean).join(" · ") || "—"}</div>
              </div>
              <span className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold ${STATUS_CLASS[viewing.status] || "bg-slate-100 text-slate-500"}`}>
                {STATUS_LABEL[viewing.status] || viewing.status}
              </span>
            </div>

            <div className="border border-black/[0.06] rounded-lg px-3 py-1 divide-y divide-black/[0.06]">
              <Row label="Pay Period" value={fmtPeriod(viewing)} />
              <Row label="Days Worked" value={viewing.days_worked ?? "—"} />
              <Row label="Daily Rate" value={peso(viewing.daily_rate)} />
              <Row label="Gross Pay" value={peso(viewing.gross_pay)} strong />
            </div>

            <div className="border border-black/[0.06] rounded-lg px-3 py-1 divide-y divide-black/[0.06]">
              <div className="py-1.5 text-slate-800 font-semibold">Deductions</div>
              <Row label="SSS" value={peso(viewing.sss_deduction)} indent />
              <Row label="PhilHealth" value={peso(viewing.philhealth_deduction)} indent />
              <Row label="Pag-IBIG" value={peso(viewing.pagibig_deduction)} indent />
              <Row label="Withholding Tax" value={peso(viewing.tax_deduction)} indent />
              <Row label="Other Deductions" value={peso(viewing.other_deductions)} indent />
              <Row label="Total Deductions" value={`−${peso(totalDeductions(viewing))}`} strong />
            </div>

            <div className="bg-slate-50 border border-black/[0.06] rounded-lg px-3 py-2 flex items-center justify-between">
              <span className="text-slate-800 font-semibold text-[13px]">Net Pay</span>
              <span className="font-mono font-semibold text-[15px] text-slate-800">{peso(viewing.net_pay)}</span>
            </div>

            {netMismatch(viewing) && (
              <div className="bg-amber-50 border border-amber-200 text-amber-700 text-[11px] rounded-lg px-3 py-2">
                Net pay does not equal gross pay minus total deductions ({peso(num(viewing.gross_pay) - totalDeductions(viewing))}). Check this record in the Employees page.
              </div>
            )}

            <div className="border border-black/[0.06] rounded-lg px-3 py-1 divide-y divide-black/[0.06]">
              <Row label="Prepared By" value={preparedByName(viewing)} />
              <Row label="Created On" value={fmtDateTime(viewing.created_at)} />
              <Row label="Paid On" value={viewing.status === "paid" ? fmtDateTime(viewing.paid_at) : "—"} />
            </div>

            {viewing.notes && (
              <div className="text-slate-600"><span className="text-slate-400">Notes: </span>{viewing.notes}</div>
            )}

            <div className="text-[10px] text-slate-400">Deductions were computed with the Sweldong Pinoy reference tables when this payroll was created.</div>

            <div className="flex justify-end">
              <button onClick={() => setViewing(null)} className="px-3.5 py-2 border border-black/[0.09] rounded-md text-xs text-slate-600 cursor-pointer hover:bg-slate-50">Close</button>
            </div>
          </div>
        </Modal>
      )}
    </HrLayout>
  );
}