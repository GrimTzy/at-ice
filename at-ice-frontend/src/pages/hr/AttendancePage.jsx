// src/pages/hr/AttendancePage.jsx
//
// HR Attendance page. View only: records come from the Attendance Station
// kiosk (face recognition + fingerprint), so HR does not edit them here.
// Date filter (default today), name search, counters, live refresh through
// the Pusher "attendance" channel (same event as HRDashboard.jsx).

import { useEffect, useState, useCallback, useMemo } from "react";
import echo from "../../echo";
import HrLayout from "../../components/HrLayout";

const RefreshIcon = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>;
const SearchIcon  = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>;

const token = () => localStorage.getItem("token") || "";
const todayLocal = () => new Date().toLocaleDateString("en-CA"); // YYYY-MM-DD, local time

// "08:05:00" -> "8:05 AM"
const fmtTime = (t) => {
  if (!t) return "—";
  const m = String(t).match(/(\d{1,2}):(\d{2})/);
  if (!m) return String(t);
  let h = Number(m[1]);
  const ap = h >= 12 ? "PM" : "AM";
  h = h % 12 || 12;
  return `${h}:${m[2]} ${ap}`;
};

const fmtHours = (h) => {
  const n = Number(h);
  return !h || Number.isNaN(n) || n === 0 ? "—" : n.toFixed(2);
};

const fmtLongDate = (ymd) =>
  new Date(`${ymd}T00:00:00`).toLocaleDateString("en-PH", { weekday: "long", month: "long", day: "numeric", year: "numeric" });

// "driver" -> "Driver", "half_day" -> "Half Day"
const capitalize = (s) =>
  s ? String(s).replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase()) : "";

const STATUS_LABEL = {
  present: "Present",
  late: "Late",
  absent: "Absent",
  half_day: "Half Day",
  on_leave: "On Leave",
};

const STATUS_CLASS = {
  present: "bg-emerald-100 text-emerald-600",
  late: "bg-amber-100 text-amber-600",
  absent: "bg-red-100 text-red-600",
  half_day: "bg-slate-100 text-slate-600",
  on_leave: "bg-cyan-100 text-cyan-600",
};

// Name: face data first (covers Guards and Baggers with no employee record)
const workerName = (a) =>
  a.face_data?.worker_name || a.employee?.name || a.worker_name || "Unknown";

const workerPosition = (a) => capitalize(a.employee?.position || a.face_data?.role) || "—";

export default function AttendancePage() {
  const [date, setDate] = useState(todayLocal());
  const [search, setSearch] = useState("");
  const [records, setRecords] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const fetchRecords = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const res = await fetch(`/api/attendances?date=${encodeURIComponent(date)}`, {
        headers: { Accept: "application/json", Authorization: `Bearer ${token()}` },
      });
      if (!res.ok) throw new Error(`Could not load attendance records (${res.status}).`);
      const data = await res.json();
      setRecords(Array.isArray(data) ? data : Array.isArray(data?.data) ? data.data : []);
    } catch (e) {
      setError(e.message || "Could not load attendance records.");
      setRecords([]);
    } finally {
      setLoading(false);
    }
  }, [date]);

  useEffect(() => { fetchRecords(); }, [fetchRecords]);

  // Live refresh when someone scans in at the Attendance Station
  useEffect(() => {
    echo.channel("attendance").listen(".attendance.recorded", () => fetchRecords());
    return () => echo.leaveChannel("attendance");
  }, [fetchRecords]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return records;
    return records.filter((a) => workerName(a).toLowerCase().includes(q));
  }, [records, search]);

  const counts = useMemo(() => {
    const c = { total: records.length, present: 0, late: 0, other: 0 };
    records.forEach((a) => {
      if (a.status === "present") c.present += 1;
      else if (a.status === "late") c.late += 1;
      else c.other += 1;
    });
    return c;
  }, [records]);

  const isToday = date === todayLocal();

  const cards = [
    { label: "Total Recorded", value: counts.total, context: isToday ? "Scanned in today" : "Scanned in on this date" },
    { label: "Present", value: counts.present, context: "On time" },
    { label: "Late", value: counts.late, context: "Past shift start" },
    { label: "Other", value: counts.other, context: "Absent, half day, on leave" },
  ];

  return (
    <HrLayout
      pageTitle="Attendance"
      breadcrumb="HRIS / Attendance"
      headerActions={
        <button
          onClick={fetchRecords}
          className="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-black/[0.09] rounded-md text-xs text-slate-500 cursor-pointer hover:border-cyan-400 hover:text-cyan-500 transition-colors"
        >
          <RefreshIcon /> Refresh
        </button>
      }
    >
      {/* FILTERS */}
      <div className="bg-white border border-black/[0.09] rounded-xl px-[18px] py-3.5 flex flex-wrap items-center gap-3">
        <div className="flex items-center gap-2">
          <label htmlFor="att-date" className="text-[11px] font-medium text-slate-400">Date</label>
          <input
            id="att-date"
            type="date"
            value={date}
            max={todayLocal()}
            onChange={(e) => e.target.value && setDate(e.target.value)}
            className="px-2.5 py-1.5 border border-black/[0.09] rounded-md text-xs text-slate-700 focus:outline-none focus:border-cyan-400"
          />
          {!isToday && (
            <button
              onClick={() => setDate(todayLocal())}
              className="px-2.5 py-1.5 border border-black/[0.09] rounded-md text-xs text-slate-500 cursor-pointer hover:border-cyan-400 hover:text-cyan-500 transition-colors"
            >
              Today
            </button>
          )}
        </div>

        <div className="flex items-center gap-2 px-2.5 py-1.5 border border-black/[0.09] rounded-md text-slate-400 w-full sm:w-64">
          <SearchIcon />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search worker name"
            className="flex-1 min-w-0 text-xs text-slate-700 bg-transparent focus:outline-none"
          />
        </div>

        <div className="flex-1" />
        <div className="text-[11px] text-slate-400">{fmtLongDate(date)}</div>
      </div>

      {/* COUNTERS */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        {cards.map((c) => (
          <div key={c.label} className="bg-white border border-black/[0.09] rounded-xl p-4 flex flex-col gap-1.5">
            <span className="text-[11px] font-medium tracking-[0.02em] text-slate-400">{c.label}</span>
            <div className="text-[26px] font-semibold font-mono tracking-tight leading-none text-slate-800">
              {loading ? "…" : error ? "—" : c.value}
            </div>
            <div className="text-[11px] text-slate-400">{c.context}</div>
          </div>
        ))}
      </div>

      {/* RECORDS TABLE */}
      <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
        <div className="px-[18px] py-3.5 border-b border-black/[0.09] flex items-center justify-between">
          <div>
            <div className="text-[13px] font-semibold text-slate-800">Attendance Records</div>
            <div className="text-[11px] text-slate-400 mt-0.5">Recorded by the Attendance Station (face recognition and fingerprint). View only.</div>
          </div>
          <span className="text-[11px] text-slate-400 font-mono">
            {loading || error ? "" : `${filtered.length} of ${records.length}`}
          </span>
        </div>

        {loading ? (
          <div className="px-[18px] py-8 text-center text-[12px] text-slate-400">Loading attendance records…</div>
        ) : error ? (
          <div className="px-[18px] py-8 text-center text-[12px] text-red-600">{error}</div>
        ) : records.length === 0 ? (
          <div className="px-[18px] py-8 text-center text-[12px] text-slate-400">
            {isToday ? "No attendance recorded yet today." : "No attendance recorded on this date."}
          </div>
        ) : filtered.length === 0 ? (
          <div className="px-[18px] py-8 text-center text-[12px] text-slate-400">No worker matches "{search}".</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="text-left text-slate-400 border-b border-black/[0.09]">
                  <th className="px-[18px] py-2.5 font-medium">Worker</th>
                  <th className="px-[18px] py-2.5 font-medium">Position</th>
                  <th className="px-[18px] py-2.5 font-medium">Time In</th>
                  <th className="px-[18px] py-2.5 font-medium">Time Out</th>
                  <th className="px-[18px] py-2.5 font-medium">Hours</th>
                  <th className="px-[18px] py-2.5 font-medium">Status</th>
                  <th className="px-[18px] py-2.5 font-medium">Notes</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((a) => (
                  <tr key={a.id} className="border-b border-black/[0.06] last:border-b-0 hover:bg-slate-50">
                    <td className="px-[18px] py-2.5 text-slate-800 font-medium whitespace-nowrap">{workerName(a)}</td>
                    <td className="px-[18px] py-2.5 text-slate-500 whitespace-nowrap">{workerPosition(a)}</td>
                    <td className="px-[18px] py-2.5 text-slate-600 font-mono whitespace-nowrap">{fmtTime(a.time_in)}</td>
                    <td className="px-[18px] py-2.5 text-slate-600 font-mono whitespace-nowrap">{fmtTime(a.time_out)}</td>
                    <td className="px-[18px] py-2.5 text-slate-600 font-mono">{fmtHours(a.hours_worked)}</td>
                    <td className="px-[18px] py-2.5">
                      <span className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold whitespace-nowrap ${STATUS_CLASS[a.status] || "bg-slate-100 text-slate-500"}`}>
                        {STATUS_LABEL[a.status] || a.status || "—"}
                      </span>
                    </td>
                    <td className="px-[18px] py-2.5 text-slate-500 max-w-[220px] truncate" title={a.notes || ""}>{a.notes || "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </HrLayout>
  );
}