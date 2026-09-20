import { useEffect, useState, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import echo from "../../echo";
import HrLayout from "../../components/HrLayout";

const RefreshIcon = () => <svg width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>;

const token = () => localStorage.getItem("token") || "";

// HR-scoped endpoints only (BI routes are admin-only and would 403 for HR)
async function getJson(url) {
  const res = await fetch(url, { headers: { Accept: "application/json", Authorization: `Bearer ${token()}` } });
  if (!res.ok) throw new Error(`${url} → ${res.status}`);
  return res.json();
}
// Accept either a plain array or a paginated { data: [...] } response
const toList = (d) => (Array.isArray(d) ? d : Array.isArray(d?.data) ? d.data : []);
const timeOf = (t) => (t ? String(t).match(/\d{2}:\d{2}/)?.[0] || String(t) : "—");
const dateOf = (d) => (d ? new Date(d).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" }) : "—");

export default function HRDashboard() {
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState({ faces: null, attendances: null, leaves: null, interviews: null });

  const fetchAll = useCallback(async () => {
    setLoading(true);
    const [faces, attendances, leaves, interviews] = await Promise.allSettled([
      getJson("/api/face-data"),
      getJson("/api/attendances"),
      getJson("/api/leave-requests"),
      getJson("/api/applicants/upcoming"),
    ]);
    // null = that endpoint failed (shown as "—"), [] = loaded but empty
    const pick = (r) => (r.status === "fulfilled" ? toList(r.value) : null);
    setData({ faces: pick(faces), attendances: pick(attendances), leaves: pick(leaves), interviews: pick(interviews) });
    setLoading(false);
  }, []);

  useEffect(() => { fetchAll(); }, [fetchAll]);

  // Live refresh when someone scans in at the Attendance Station
  useEffect(() => {
    echo.channel("attendance").listen(".attendance.recorded", () => fetchAll());
    return () => echo.leaveChannel("attendance");
  }, [fetchAll]);

  const todayStr = new Date().toLocaleDateString("en-CA"); // YYYY-MM-DD, local time
  const todayAttendance = (data.attendances || []).filter((a) => String(a.date || a.created_at || "").slice(0, 10) === todayStr);
  const lateToday = todayAttendance.filter((a) => a.status === "late").length;
  const pendingLeaves = (data.leaves || []).filter((l) => l.status === "pending");

  const show = (list, value) => (loading ? "…" : list === null ? "—" : value);

  const cards = [
    { label: "Enrolled Workers", value: show(data.faces, data.faces?.length), context: "Faces registered for attendance" },
    { label: "Attendance Today", value: show(data.attendances, todayAttendance.length), context: `${lateToday} late` },
    { label: "Pending Leave Requests", value: show(data.leaves, pendingLeaves.length), context: "Awaiting review" },
    { label: "Upcoming Interviews", value: show(data.interviews, data.interviews?.length), context: "Scheduled applicants" },
  ];

  const workerName = (a) => a.face_data?.worker_name || a.faceData?.worker_name || a.employee?.name || a.worker_name || "Unknown";
  const leaveName = (l) => l.employee?.name || l.employee_name || (l.employee_id ? `Employee #${l.employee_id}` : "Employee");

  const empty = (text) => <div className="px-[18px] py-6 text-center text-[12px] text-slate-400">{text}</div>;

  return (
    <HrLayout
      pageTitle="HR Dashboard"
      breadcrumb="HR Dashboard"
      headerActions={
        <button
          onClick={fetchAll}
          className="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-black/[0.09] rounded-md text-xs text-slate-500 cursor-pointer hover:border-cyan-400 hover:text-cyan-500 transition-colors"
        >
          <RefreshIcon /> Refresh
        </button>
      }
    >
      {/* STAT CARDS — plain text, no decorative icons/colors */}
      <div className="grid grid-cols-4 gap-3">
        {cards.map((c) => (
          <div key={c.label} className="bg-white border border-black/[0.09] rounded-xl p-4 flex flex-col gap-1.5">
            <span className="text-[11px] font-medium tracking-[0.02em] text-slate-400">{c.label}</span>
            <div className="text-[26px] font-semibold font-mono tracking-tight leading-none text-slate-800">{c.value}</div>
            <div className="text-[11px] text-slate-400">{c.context}</div>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-[1fr_340px] gap-3">

        {/* ATTENDANCE TODAY */}
        <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
          <div className="px-[18px] py-3.5 border-b border-black/[0.09] flex items-center justify-between">
            <div>
              <div className="text-[13px] font-semibold text-slate-800">Attendance Today</div>
              <div className="text-[11px] text-slate-400 mt-0.5">{dateOf(new Date())}</div>
            </div>
            <span className="text-[11px] text-slate-400 font-mono">{data.attendances === null ? "—" : `${todayAttendance.length} recorded`}</span>
          </div>
          {data.attendances === null && !loading ? empty("Could not load attendance records.")
            : todayAttendance.length === 0 ? empty("No attendance recorded yet today.")
            : (
              <table className="w-full text-xs">
                <thead>
                  <tr className="text-left text-slate-400 border-b border-black/[0.09]">
                    <th className="px-[18px] py-2.5 font-medium">Worker</th>
                    <th className="px-[18px] py-2.5 font-medium">Time In</th>
                    <th className="px-[18px] py-2.5 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {todayAttendance.slice(0, 10).map((a) => (
                    <tr key={a.id} className="border-b border-black/[0.06] last:border-b-0">
                      <td className="px-[18px] py-2.5 text-slate-800 font-medium">{workerName(a)}</td>
                      <td className="px-[18px] py-2.5 text-slate-500 font-mono">{timeOf(a.time_in)}</td>
                      <td className="px-[18px] py-2.5">
                        <span className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold capitalize ${a.status === "late" ? "bg-amber-100 text-amber-600" : "bg-slate-100 text-slate-500"}`}>
                          {a.status || "present"}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
        </div>

        {/* RIGHT COLUMN */}
        <div className="flex flex-col gap-3">

          {/* PENDING LEAVE */}
          <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
            <div className="px-[18px] py-3.5 border-b border-black/[0.09] text-[13px] font-semibold text-slate-800">Pending Leave Requests</div>
            {data.leaves === null && !loading ? empty("Could not load leave requests.")
              : pendingLeaves.length === 0 ? empty("No pending leave requests.")
              : pendingLeaves.slice(0, 5).map((l, i, arr) => (
                <div key={l.id} className={`px-[18px] py-2.5 ${i < arr.length - 1 ? "border-b border-black/[0.06]" : ""}`}>
                  <div className="text-xs font-medium text-slate-800">{leaveName(l)}</div>
                  <div className="text-[11px] text-slate-400 mt-0.5">
                    {(l.leave_type || l.type || "Leave")} · {dateOf(l.start_date)}{l.end_date ? ` – ${dateOf(l.end_date)}` : ""}
                  </div>
                </div>
              ))}
          </div>

          {/* UPCOMING INTERVIEWS */}
          <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
            <div className="px-[18px] py-3.5 border-b border-black/[0.09] text-[13px] font-semibold text-slate-800">Upcoming Interviews</div>
            {data.interviews === null && !loading ? empty("Could not load interviews.")
              : (data.interviews || []).length === 0 ? empty("No upcoming interviews.")
              : data.interviews.slice(0, 5).map((a, i, arr) => (
                <div key={a.id} className={`px-[18px] py-2.5 flex items-center justify-between gap-3 ${i < arr.length - 1 ? "border-b border-black/[0.06]" : ""}`}>
                  <div className="min-w-0">
                    <div className="text-xs font-medium text-slate-800 truncate">{a.name || a.applicant_name}</div>
                    <div className="text-[11px] text-slate-400 mt-0.5 truncate">{a.position_applied || a.position || "—"}</div>
                  </div>
                  <div className="text-[11px] text-slate-500 font-mono flex-shrink-0">{dateOf(a.interview_date)}</div>
                </div>
              ))}
          </div>

          {/* QUICK ACTION */}
          <button
            onClick={() => navigate("/hr/face-enrollment")}
            className="w-full py-2.5 bg-cyan-500 hover:bg-cyan-600 active:bg-cyan-700 text-white text-[13px] font-semibold rounded-xl transition-colors shadow-lg shadow-cyan-500/20 cursor-pointer"
          >
            Enroll a Worker's Face
          </button>
        </div>
      </div>
    </HrLayout>
  );
}