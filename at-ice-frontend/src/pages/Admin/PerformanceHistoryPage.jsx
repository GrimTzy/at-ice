import { useEffect, useMemo, useRef, useState } from "react";
import { Chart } from "chart.js/auto";
import { Link, useParams } from "react-router-dom";
import AdminLayout from "../../components/AdminLayout";
import { Icon } from "../../components/icons";
import { printPerformanceHistory } from "../../lib/printPerformanceHistory";
import "./performanceHistory.css";

// Admin > HRIS > Performance > employee name (a page, not a window). Read-only.
// One filter (Month or Quarterly, and the year) drives every section: rating
// trend, attendance, goals, work records and feedback, so the numbers always
// agree. Data: GET /api/performance/{employee}/history (App\Support\PerformanceHistory).
// Published reviews only; ratings are the snapshot taken when the review was
// published, older reviews are recalculated from the records that exist and say so.

const TONE_OF_LABEL = { "Outstanding": "mint", "Very Satisfactory": "cyan", "Satisfactory": "blue", "Needs Improvement": "yellow", "Poor": "rose" };
const LABELS = [[4.5, "Outstanding"], [3.5, "Very Satisfactory"], [2.5, "Satisfactory"], [1.5, "Needs Improvement"], [0, "Poor"]];
const labelOf = (r) => (r == null ? null : (LABELS.find(([min]) => r >= min) || LABELS[4])[1]);
const toneOf = (r) => TONE_OF_LABEL[labelOf(r)] || "slate";
const ROLE_LABEL = { admin: "Admin", hr: "HR", driver: "Driver", salesman: "Salesman", checker: "Checker", maintenance: "Maintenance", employee: "Employee", customer: "Customer" };

const dmy = (d) => (d ? new Date(`${d.slice(0, 10)}T00:00:00`).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" }) : "—");
const dm = (d) => new Date(`${d.slice(0, 10)}T00:00:00`).toLocaleDateString("en-PH", { month: "short", day: "numeric" });
const periodText = (s, e) => `${dm(s)} – ${dmy(e)}`;
const pct = (v) => (v == null ? "—" : `${Math.round(v)}%`);
const one = (v) => (v == null ? "—" : Number(v).toFixed(1));
const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
const initials = (name) => (name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

function api(path) {
  return fetch(`/api/${path}`, { headers: { Accept: "application/json", Authorization: `Bearer ${localStorage.getItem("token") || ""}` } })
    .then(async (res) => {
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.errors ? Object.values(data.errors).flat()[0] : data.message || `Request failed (${res.status})`);
      return data;
    });
}

// The 2x2 photo sits on a private disk behind the Admin token, so it is fetched
// with the token and shown from a blob. No photo (or a failed fetch): initials.
function usePhoto(employeeId, has) {
  const [photo, setPhoto] = useState({ id: null, url: "" });
  useEffect(() => {
    if (!has) return undefined;
    let gone = false;
    let made = "";
    fetch(`/api/performance/${employeeId}/photo`, { headers: { Authorization: `Bearer ${localStorage.getItem("token") || ""}` } })
      .then((r) => (r.ok ? r.blob() : null))
      .then((b) => { if (b && !gone) { made = URL.createObjectURL(b); setPhoto({ id: employeeId, url: made }); } })
      .catch(() => {});
    return () => { gone = true; if (made) URL.revokeObjectURL(made); };
  }, [employeeId, has]);
  return has && photo.id === employeeId ? photo.url : "";
}

function Card({ icon, title, sub, cover, right, children, className = "" }) {
  return (
    <section className={`ph-card ${className}`}>
      <div className="ph-card-head">
        <div className="ph-card-title">
          <span className="ph-icon-well" aria-hidden="true"><Icon name={icon} size={18} /></span>
          <div>
            <h3>{title}</h3>
            {sub && <div className="ph-card-sub">{sub}</div>}
            {cover && <div className="ph-cover">{cover}</div>}
          </div>
        </div>
        {right}
      </div>
      {children}
    </section>
  );
}

const Flag = ({ children }) => <span className="ph-flag">{children}</span>;

// Month | Quarterly and the year. The same state sits above Attendance and
// Goals, so changing either one changes the whole page.
function PeriodFilter({ by, pick, years, onBy, onPick, id }) {
  return (
    <div className="ph-filter ph-no-print">
      {/* the same Day / Month / Year toggle and picker as the dashboard's Sales Overview */}
      <div className="flex items-center bg-slate-100 rounded-md p-0.5 gap-0.5" role="group" aria-label="Show by">
        {[["month", "Monthly"], ["quarter", "Quarterly"]].map(([k, label]) => (
          <button key={k} type="button" data-no-confirm onClick={() => onBy(k)} aria-pressed={by === k} className={`px-2.5 py-1 text-[10px] font-medium rounded transition-colors border-0 cursor-pointer ${by === k ? "bg-white text-slate-800 shadow-sm" : "text-slate-400 bg-transparent hover:text-slate-600"}`}>{label}</button>
        ))}
      </div>
      <label className="flex items-center gap-1.5 bg-slate-100/60 rounded-md p-0.5 pl-2 text-[10px] text-slate-500">
        <Icon name="calendar" size={12} />
        <span className="sr-only-ph">{by === "month" ? "Year" : "Quarters shown"}</span>
        <select id={id} value={pick} onChange={(e) => onPick(e.target.value)}
          className="px-2 py-1 rounded bg-white shadow-sm text-[10px] font-medium text-slate-800 outline-none cursor-pointer">
          {by === "quarter" && <option value="last8">Last 8 quarters</option>}
          {years.map((y) => <option key={y} value={`y${y}`}>{y}</option>)}
        </select>
      </label>
    </div>
  );
}

export default function PerformanceHistoryPage() {
  const { employeeId } = useParams();
  const thisYear = new Date().getFullYear();
  const years = useMemo(() => Array.from({ length: 5 }, (_, i) => thisYear - i), [thisYear]);
  const [by, setBy] = useState("quarter");
  const [pick, setPick] = useState("last8");
  const [res, setRes] = useState({ key: "", data: null, error: "" });
  const [attempt, setAttempt] = useState(0);
  const [picked, setPicked] = useState(null);
  const [bucketFilter, setBucketFilter] = useState("");
  const [chartMode, setChartMode] = useState("line"); // line | bar, like the dashboard chart

  const query = useMemo(() => {
    const q = new URLSearchParams({ by });
    if (pick !== "last8") {
      const y = Number(pick.slice(1));
      const today = ymd(new Date());
      const end = `${y}-12-31`;
      q.set("from", `${y}-01-01`);
      q.set("to", end > today ? today : end);
    }
    return `?${q}`;
  }, [by, pick]);

  // One request per employee, filter and retry. The previous data stays on screen
  // (aria-busy) until the new answer arrives, so changing the filter does not flash.
  const reqKey = `${employeeId}|${query}|${attempt}`;
  useEffect(() => {
    let gone = false;
    api(`performance/${employeeId}/history${query}`)
      .then((d) => { if (!gone) setRes({ key: reqKey, data: d, error: "" }); })
      .catch((e) => { if (!gone) setRes((r) => ({ key: reqKey, data: r.data, error: e.message })); });
    return () => { gone = true; };
  }, [employeeId, query, reqKey]);
  const loading = res.key !== reqKey;
  const data = res.data && String(res.data.employee.id) === String(employeeId) ? res.data : null;
  const error = loading ? "" : res.error;

  const photo = usePhoto(employeeId, !!data?.employee?.has_photo);
  const emp = data?.employee;

  function changeBy(k) {
    setBy(k);
    setPicked(null);
    setBucketFilter("");
    setPick(k === "month" ? `y${thisYear}` : "last8");
  }
  function changePick(v) { setPick(v); setPicked(null); setBucketFilter(""); }

  const cover = data ? `${dmy(data.range.from)} – ${dmy(data.range.to)}` : "";
  const unitWord = by === "month" ? "month" : "quarter";
  const dataTrend = data?.trend.filter((q) => q.average != null) || [];
  const sel = data ? (dataTrend.some((q) => q.key === picked) ? picked : (dataTrend.length ? dataTrend[dataTrend.length - 1].key : null)) : null;
  const selBucket = data?.trend.find((q) => q.key === sel);
  const recalcAny = data && data.counts.recalculated > 0;
  const recs = data ? data.records.items.filter((r) => !bucketFilter || r.bucket === bucketFilter) : [];
  const filterProps = { by, pick, years, onBy: changeBy, onPick: changePick };

  return (
    <AdminLayout pageTitle="Performance history" breadcrumb={`HRIS / Performance / ${emp?.name || "Employee"}`}>
      <div className="ph-page" aria-busy={loading}>
        <div className="ph-toolbar ph-no-print">
          <Link to="/admin/performance" className="ph-btn"><Icon name="back" size={15} /> Back to Performance</Link>
          <button type="button" data-no-confirm className="ph-btn" onClick={() => printPerformanceHistory(data, photo)} disabled={!data}
            title="Opens a printable copy. Choose Save as PDF in the print dialog.">
            <Icon name="print" size={16} /> Print / Save PDF
          </button>
        </div>

        {error && (
          <div className="ph-error" role="alert">
            <span>{error}</span>
            <button type="button" data-no-confirm className="ph-btn" onClick={() => setAttempt((n) => n + 1)}><Icon name="refresh" size={15} /> Retry</button>
          </div>
        )}

        {!data && !error && <Skeleton />}

        {data && (
          <>
            {/* Employee */}
            <section className="ph-card" aria-label="Employee">
              <div className="ph-profile">
                <div className="ph-avatar" aria-hidden="true">{photo ? <img src={photo} alt="" /> : initials(emp.name)}</div>
                <div className="ph-person">
                  <h2 className="ph-person-name">{emp.name}</h2>
                  <div className="ph-person-id">{emp.code}</div>
                </div>
                <dl className="ph-facts">
                  {emp.department && <div className="ph-fact"><dt>Department</dt><dd>{emp.department}</dd></div>}
                  {emp.position && <div className="ph-fact"><dt>Position</dt><dd>{emp.position}</dd></div>}
                  {emp.date_hired && <div className="ph-fact"><dt>Date hired</dt><dd>{dmy(emp.date_hired)}</dd></div>}
                  {emp.shift && <div className="ph-fact"><dt>Shift</dt><dd style={{ textTransform: "capitalize" }}>{emp.shift}</dd></div>}
                </dl>
                <div className="ph-rating-box">
                  <div className="ph-fact"><dt>Latest published rating</dt></div>
                  {data.latest && data.latest.rating != null ? (
                    <>
                      <div className="ph-rating-num">{one(data.latest.rating)} <span className={`ph-chip ph-tone-${toneOf(data.latest.rating)}`}>{data.latest.rating_label}</span></div>
                      <div className="ph-rating-note">
                        Cutoff {periodText(data.latest.period_start, data.latest.period_end)}
                        {data.latest.source === "recalculated" && <> · <Flag>Recalculated</Flag></>}
                      </div>
                    </>
                  ) : (
                    <>
                      <div className="ph-rating-num" style={{ fontSize: 18 }}>No rating yet</div>
                      <div className="ph-rating-note">{data.latest ? `The latest published appraisal (${periodText(data.latest.period_start, data.latest.period_end)}) has no work days.` : "No appraisal has been published for this employee."}</div>
                    </>
                  )}
                </div>
              </div>
            </section>

            {/* Trend */}
            <Card icon="chart" title="Performance history trend" sub={`Average of the published cutoff ratings in each ${unitWord} (1 to 5)`}
              cover={`Published cutoffs ending ${cover} · ${data.counts.published} published${recalcAny ? `, ${data.counts.recalculated} recalculated` : ""}`}
              right={(
                <div className="flex items-center bg-slate-100 rounded-md p-0.5 gap-0.5 ph-no-print" role="group" aria-label="Chart type">
                  {["line", "bar"].map((k) => (
                    <button key={k} type="button" data-no-confirm onClick={() => setChartMode(k)} aria-pressed={chartMode === k} className={`px-2.5 py-1 text-[10px] font-medium rounded transition-colors border-0 cursor-pointer ${chartMode === k ? "bg-white text-slate-800 shadow-sm" : "text-slate-400 bg-transparent hover:text-slate-600"}` + " capitalize"}>{k}</button>
                  ))}
                </div>
              )}>
              {dataTrend.length === 0 ? (
                <div className="ph-empty">No published ratings in this date range.</div>
              ) : (
                <>
                  <TrendChart buckets={data.trend} sel={sel} onPick={setPicked} unitWord={unitWord} mode={chartMode} />
                  <div className="ph-detail" aria-live="polite">
                    {selBucket && selBucket.average != null ? (
                      <>
                        <strong>{selBucket.label}</strong> · average <strong>{one(selBucket.average)}</strong> {labelOf(selBucket.average)} from {selBucket.count} published cutoff {selBucket.count === 1 ? "rating" : "ratings"}
                        <ul>
                          {selBucket.ratings.map((r) => (
                            <li key={r.period_start}>
                              {periodText(r.period_start, r.period_end)}: <strong className="tabular-nums">{one(r.rating)}</strong>
                              {r.manual && <> · set by Admin</>}
                              {r.source === "recalculated" && <> · <Flag>Recalculated</Flag></>}
                            </li>
                          ))}
                        </ul>
                      </>
                    ) : <>{selBucket?.label}: no published rating.</>}
                  </div>
                </>
              )}
              <div className="ph-chart-table"><table className="ph-table">
                <caption>Average published rating per {unitWord}, {cover}</caption>
                <thead><tr><th scope="col">{by === "month" ? "Month" : "Quarter"}</th><th scope="col">Average rating</th><th scope="col">Published cutoffs</th><th scope="col">Cutoff ratings</th></tr></thead>
                <tbody>
                  {data.trend.map((q) => (
                    <tr key={q.key}>
                      <th scope="row">{q.label}</th>
                      <td>{q.average == null ? "No published rating" : one(q.average)}</td>
                      <td>{q.count}</td>
                      <td>{q.ratings.map((r) => `${periodText(r.period_start, r.period_end)} ${one(r.rating)}${r.source === "recalculated" ? " (recalculated)" : ""}`).join("; ") || "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table></div>
            </Card>

            {/* Attendance */}
            <Card icon="calendar" title="Attendance history" sub={`Days present against expected work days, by ${unitWord}`}
              cover={`Covers ${cover}, from the hire date and up to today. Holidays and approved leave are not expected days. A dash means no expected work days.`}
              right={<PeriodFilter {...filterProps} id="ph-filter-att" />}>
              <div className="ph-table-wrap">
                <table className="ph-table ph-fixed">
                  <thead><tr><th scope="col">{by === "month" ? "Month" : "Quarter"}</th><th scope="col" className="ph-num">Present</th><th scope="col" className="ph-num">Absent</th><th scope="col" className="ph-num">Attendance rate</th><th scope="col" className="ph-num">On time</th></tr></thead>
                  <tbody>
                    {data.attendance.map((q) => (
                      <tr key={q.key}>
                        <th scope="row" style={{ fontWeight: 600 }}>{q.label}</th>
                        {q.work_days === 0 ? (
                          [0, 1, 2, 3].map((i) => <td key={i} title="No expected work days" aria-label="No expected work days">—</td>)
                        ) : (
                          <>
                            <td className="ph-num">{q.present_days} / {q.work_days}</td>
                            <td className="ph-num">{q.absent_days}</td>
                            <td className="ph-num"><span className={`ph-chip ph-tone-${q.attendance_rate >= 95 ? "mint" : q.attendance_rate >= 85 ? "yellow" : "rose"}`}>{pct(q.attendance_rate)}</span></td>
                            <td className="ph-num">{pct(q.punctuality_rate)}</td>
                          </>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>

            {/* Goals */}
            <Card icon="check" title="Goal completion progress" sub={`Role work done against the goal, by ${unitWord}, from the published appraisals`}
              cover={data.goals.applicable ? `Counted in ${data.goals.unit}. Work above a cutoff's own goal is not counted toward the ${unitWord}. Covers cutoffs ending ${cover}. A dash means no published cutoff with a goal.` : `Covers ${cover}`}
              right={<PeriodFilter {...filterProps} id="ph-filter-goal" />}>
              {!data.goals.applicable ? (
                <div className="ph-empty">No goal assigned.<div className="ph-note" style={{ marginTop: 4 }}>Goals apply to Drivers, Salesmen, Checkers and Maintenance.</div></div>
              ) : (
                <>
                  <div className="ph-table-wrap">
                    <table className="ph-table ph-fixed">
                      <thead><tr><th scope="col">{by === "month" ? "Month" : "Quarter"}</th><th scope="col" className="ph-num">Done / goal</th><th scope="col">Progress</th></tr></thead>
                      <tbody>
                        {data.goals.quarters.map((q) => {
                          return (
                            <tr key={q.key}>
                              <th scope="row" style={{ fontWeight: 600 }}>{q.label}</th>
                              {q.target == null ? (
                                [0, 1].map((i) => <td key={i} title="No published cutoff with a goal" aria-label="No published cutoff with a goal">—</td>)
                              ) : (
                                <>
                                  <td className="ph-num">
                                    {q.done} / {q.target}
                                    {q.counted !== q.done && <div className="ph-note">{q.counted} counted</div>}
                                    {q.recalculated && <div><Flag>Recalculated</Flag></div>}
                                  </td>
                                  <td>
                                    {/* the same percent, bar and caption as the Goal Completion column on the Performance page */}
                                    <div className="inline-flex flex-col items-center gap-1 min-w-[110px]">
                                      <span className="text-[12px] font-semibold text-slate-700 tabular-nums">{pct(q.completion)}</span>
                                      <div className="goal-bar w-full rounded-full overflow-hidden" aria-hidden="true">
                                        <div className="h-full bg-cyan-600" style={{ width: `${Math.min(100, q.completion)}%` }} />
                                      </div>
                                    </div>
                                  </td>
                                </>
                              )}
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                  <div className="ph-note" style={{ marginTop: 8 }}>Source: {data.goals.source}</div>
                </>
              )}
            </Card>

            {/* Work records */}
            {data.goals.applicable && (
              <Card icon="clipboard" title="Work records" sub="The actual work behind the goal figures, newest first"
                cover={`${data.records.items.length}${data.records.truncated ? "+" : ""} records, ${cover}. Taken live from the source records, so they can differ from a published snapshot if records changed after publishing.`}
                right={(
                  <div className="ph-no-print">
                    <label className="ph-field-label" htmlFor="ph-bucket">Show</label>
                    <select id="ph-bucket" className="ph-field" value={bucketFilter} onChange={(e) => setBucketFilter(e.target.value)}>
                      <option value="">All {by === "month" ? "months" : "quarters"}</option>
                      {data.trend.map((b) => <option key={b.key} value={b.key}>{b.label}</option>)}
                    </select>
                  </div>
                )}>
                {recs.length === 0 ? (
                  <div className="ph-empty">No work records in this date range.</div>
                ) : (
                  <div className="ph-table-wrap">
                    <table className="ph-table ph-stack">
                      <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Reference</th><th scope="col">Details</th></tr></thead>
                      <tbody>
                        {recs.map((r, i) => (
                          <tr key={`${r.at}-${r.type}-${r.ref}-${i}`}>
                            <td data-label="Date" style={{ whiteSpace: "nowrap" }}>{dmy(r.at)} <span className="ph-note">{r.at.slice(11)}</span></td>
                            <td data-label="Type">{r.type}</td>
                            <td data-label="Reference" style={{ overflowWrap: "anywhere" }}>{r.to ? <Link to={r.to} className="ph-link">{r.ref || "—"}</Link> : (r.ref || "—")}</td>
                            <td data-label="Details" style={{ overflowWrap: "anywhere" }}>{r.detail || "—"}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
                {data.records.truncated && <div className="ph-note" style={{ marginTop: 8 }}>Only the newest 200 records are listed. Narrow the year to see older ones.</div>}
              </Card>
            )}

            {/* Feedback */}
            <Card icon="message" title="Review feedback" sub="Remarks from published performance reviews, newest first" cover={`Published appraisals for cutoffs ending ${cover}`}>
              {data.feedback.length === 0 ? (
                <div className="ph-empty">No review feedback in this date range.</div>
              ) : (
                <div className="ph-table-wrap">
                  <table className="ph-table ph-stack">
                    <thead><tr><th scope="col">Feedback</th><th scope="col">Rating</th><th scope="col">Published</th></tr></thead>
                    <tbody>{data.feedback.map((f) => <FeedbackRow key={f.id} f={f} />)}</tbody>
                  </table>
                </div>
              )}
            </Card>
          </>
        )}
      </div>
    </AdminLayout>
  );
}

function FeedbackRow({ f }) {
  const [open, setOpen] = useState(false);
  const long = f.remarks.length > 320;
  const tone = f.rating == null ? "slate" : toneOf(f.rating);
  return (
    <tr>
      <td data-label="Feedback" style={{ minWidth: 260 }}>
        <div className="ph-fb-period"><span className={`ph-fb-dot ph-dot-${tone}`} aria-hidden="true" />{periodText(f.period_start, f.period_end)}</div>
        <p className="ph-tl-text" style={long && !open ? { display: "-webkit-box", WebkitLineClamp: 3, WebkitBoxOrient: "vertical", overflow: "hidden" } : undefined}>{f.remarks}</p>
        {long && <button type="button" data-no-confirm className="ph-link-btn ph-no-print" aria-expanded={open} onClick={() => setOpen(!open)}>{open ? "Show less" : "Show more"}</button>}
      </td>
      <td data-label="Rating">{f.rating != null ? <span className={`ph-chip ph-tone-${tone}`}>{one(f.rating)} {f.rating_label}</span> : "—"}</td>
      <td data-label="Published" style={{ overflowWrap: "anywhere" }}>
        {f.published_at ? dmy(f.published_at) : "—"}
        <div className="ph-note">{f.reviewer ? `${f.reviewer.name} (${ROLE_LABEL[f.reviewer.role] || f.reviewer.role})` : "Reviewer not recorded"}</div>
      </td>
    </tr>
  );
}

// Trend chart: the same Chart.js look as the dashboard's Sales Overview (colors,
// fonts, grid, white tooltip, Line or Bar). A bucket without a published rating
// is a gap (no zero). Click a point or bar, or use the arrow keys, to inspect it.
function TrendChart({ buckets, sel, onPick, unitWord, mode }) {
  const canvasRef = useRef(null);
  const dataKey = JSON.stringify({ rows: buckets.map((b) => [b.key, b.label, b.average, b.count]), sel, mode });

  useEffect(() => {
    const ctx = canvasRef.current;
    if (!ctx) return undefined;
    const { rows, sel: s, mode: m } = JSON.parse(dataKey);
    const chart = new Chart(ctx, {
      type: m === "bar" ? "bar" : "line",
      data: {
        labels: rows.map((r) => r[1]),
        datasets: [{
          label: "Average rating",
          data: rows.map((r) => r[2]),
          backgroundColor: m === "bar" ? rows.map((r) => (r[0] === s ? "#0098cc" : "rgba(0,152,204,0.85)")) : "rgba(0,152,204,0.85)",
          borderColor: "#0098cc",
          borderWidth: m === "line" ? 2 : 0,
          borderRadius: 4,
          borderSkipped: false,
          tension: 0.4,
          fill: false,
          spanGaps: false,
          pointRadius: rows.map((r) => (r[0] === s ? 6 : 3)),
          pointHoverRadius: 6,
          barPercentage: 0.75,
          categoryPercentage: 0.72,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        onClick: (_e, els) => { if (els.length) onPick(rows[els[0].index][0]); },
        onHover: (e, els) => { if (e.native?.target) e.native.target.style.cursor = els.length ? "pointer" : "default"; },
        plugins: {
          legend: { display: true, position: "top", align: "end", labels: { color: "#5a6a85", font: { size: 11 }, boxWidth: 12, boxHeight: 12, padding: 14 } },
          tooltip: {
            backgroundColor: "#fff", borderColor: "rgba(0,0,0,0.10)", borderWidth: 1, titleColor: "#5a6a85", bodyColor: "#1a2332", padding: 10,
            callbacks: { label: (c) => ` Average: ${Number(c.raw).toFixed(1)} from ${rows[c.dataIndex][3]} published ${rows[c.dataIndex][3] === 1 ? "rating" : "ratings"}` },
          },
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: "#94a3b8", font: { size: 11 } } },
          y: { min: 1, max: 5, grid: { color: "rgba(0,0,0,0.06)" }, ticks: { color: "#94a3b8", stepSize: 1 } },
        },
      },
    });
    return () => chart.destroy();
  }, [dataKey, onPick]);

  function onKeyDown(e) {
    if (e.key !== "ArrowLeft" && e.key !== "ArrowRight") return;
    const withData = buckets.filter((b) => b.average != null);
    const i = withData.findIndex((b) => b.key === sel);
    const next = withData[Math.min(withData.length - 1, Math.max(0, i + (e.key === "ArrowRight" ? 1 : -1)))];
    if (next) { e.preventDefault(); onPick(next.key); }
  }

  return (
    <div className="ph-chart-box" tabIndex={0} role="group" onKeyDown={onKeyDown}
      aria-label={`Average published rating per ${unitWord}. Use the left and right arrow keys to move between ${unitWord}s. The same values are listed in the table below.`}>
      <canvas ref={canvasRef} role="img" aria-label={`Chart of the average published rating per ${unitWord}`} />
    </div>
  );
}

function Skeleton() {
  return (
    <div role="status" aria-label="Loading performance history" style={{ display: "flex", flexDirection: "column", gap: 18 }}>
      <div className="ph-sk" style={{ height: 120 }} />
      <div className="ph-sk" style={{ height: 300 }} />
      <div className="ph-sk" style={{ height: 260 }} />
      <div className="ph-sk" style={{ height: 260 }} />
    </div>
  );
}
