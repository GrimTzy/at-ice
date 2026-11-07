import { PRINT_BASE_CSS, docFileName, printDocument } from "./printDocument";
import { COMPANY } from "./company";

// The employee performance history as a printable hard copy (Admin > HRIS >
// Performance > employee name > Print / Save PDF). Same print base as the
// payslip, invoice and biodata (lib/printDocument.js), so it fits any paper
// and "Save as PDF" is named <doc-type>_<employee>_<range>. It prints what the
// page shows for the chosen Monthly / Quarterly filter and date range.

const esc = (v) => String(v ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const dmy = (v) => (v ? new Date(`${String(v).slice(0, 10)}T00:00:00`).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" }) : "—");
const dm = (v) => new Date(`${String(v).slice(0, 10)}T00:00:00`).toLocaleDateString("en-PH", { month: "short", day: "numeric" });
const periodText = (s, e) => `${dm(s)} – ${dmy(e)}`;
const pct = (v) => (v == null ? "—" : `${Math.round(v)}%`);
const one = (v) => (v == null ? "—" : Number(v).toFixed(1));
const ROLE_LABEL = { admin: "Admin", hr: "HR", driver: "Driver", salesman: "Salesman", checker: "Checker", maintenance: "Maintenance", employee: "Employee" };
const LABELS = [[4.5, "Outstanding"], [3.5, "Very Satisfactory"], [2.5, "Satisfactory"], [1.5, "Needs Improvement"], [0, "Poor"]];
const labelOf = (r) => (r == null ? "" : (LABELS.find(([min]) => r >= min) || LABELS[4])[1]);

// Line chart as inline SVG (prints as vector, no canvas needed)
function chartSvg(buckets) {
  const W = 700, H = 220, L = 34, R = 20, T = 24, B = 34;
  const iw = W - L - R, ih = H - T - B, n = buckets.length;
  const x = (i) => (n === 1 ? L + iw / 2 : L + (i * iw) / (n - 1));
  const y = (v) => T + ((5 - v) / 4) * ih;
  const runs = [];
  buckets.forEach((q, i) => {
    if (q.average == null) return;
    const last = runs[runs.length - 1];
    if (last && last.end === i - 1) { last.pts.push([x(i), y(q.average)]); last.end = i; } else runs.push({ pts: [[x(i), y(q.average)]], end: i });
  });
  const dense = n > 9;
  return `<svg viewBox="0 0 ${W} ${H}" width="100%" role="img" aria-label="Average rating per period">
    ${[1, 2, 3, 4, 5].map((v) => `<line x1="${L}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}" stroke="#e5e7eb"/><text x="${L - 8}" y="${y(v) + 4}" text-anchor="end" font-size="10" fill="#6b7280">${v}</text>`).join("")}
    ${runs.filter((r) => r.pts.length > 1).map((r) => `<path d="${r.pts.map((p, j) => `${j ? "L" : "M"}${p[0]},${p[1]}`).join(" ")}" fill="none" stroke="#0098cc" stroke-width="2.5" stroke-linejoin="round"/>`).join("")}
    ${buckets.map((q, i) => `${!dense || i % 2 === 0 ? `<text x="${x(i)}" y="${H - 12}" text-anchor="middle" font-size="10" fill="#6b7280">${esc(q.label)}</text>` : ""}
      ${q.average == null ? "" : `<circle cx="${x(i)}" cy="${y(q.average)}" r="4.5" fill="#fff" stroke="#0098cc" stroke-width="2.5"/><text x="${x(i)}" y="${y(q.average) - 10}" text-anchor="middle" font-size="10.5" font-weight="700" fill="#111827">${one(q.average)}</text>`}`).join("")}
  </svg>`;
}

async function asDataUrl(url) {
  try {
    const blob = await (await fetch(url)).blob();
    return await new Promise((resolve) => {
      const r = new FileReader();
      r.onload = () => resolve(r.result);
      r.onerror = () => resolve(null);
      r.readAsDataURL(blob);
    });
  } catch {
    return null;
  }
}

/** @param data the /api/performance/{employee}/history answer; photoUrl an object URL of the photo, or "" */
export async function printPerformanceHistory(data, photoUrl = "") {
  const emp = data.employee;
  const by = data.by;
  const unit = by === "month" ? "Month" : "Quarter";
  const unitWord = by === "month" ? "month" : "quarter";
  const cover = `${dmy(data.range.from)} – ${dmy(data.range.to)}`;
  const photo = photoUrl ? await asDataUrl(photoUrl) : null;
  const recalc = data.counts.recalculated > 0;
  const flag = '<span class="flag">Recalculated</span>';

  const trendRows = data.trend.map((q) => `<tr><td>${esc(q.label)}</td><td>${q.average == null ? "—" : `${one(q.average)} ${esc(labelOf(q.average))}`}</td><td>${q.count}</td>
    <td>${q.ratings.map((r) => `${esc(periodText(r.period_start, r.period_end))}: ${one(r.rating)}${r.source === "recalculated" ? ` ${flag}` : ""}`).join("<br>") || "—"}</td></tr>`).join("");
  const attRows = data.attendance.map((q) => (q.work_days === 0
    ? `<tr><td>${esc(q.label)}</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>`
    : `<tr><td>${esc(q.label)}</td><td>${q.present_days} / ${q.work_days}</td><td>${q.absent_days}</td><td>${pct(q.attendance_rate)}</td><td>${pct(q.punctuality_rate)}</td></tr>`)).join("");
  const goalRows = data.goals.quarters.map((q) => (q.target == null
    ? `<tr><td>${esc(q.label)}</td><td>—</td><td>—</td></tr>`
    : `<tr><td>${esc(q.label)}</td><td>${q.done} / ${q.target}${q.counted !== q.done ? `<br><small>${q.counted} counted</small>` : ""}${q.recalculated ? `<br>${flag}` : ""}</td>
      <td><div class="bar"><span style="width:${Math.min(100, q.completion)}%"></span></div>${pct(q.completion)}</td></tr>`)).join("");
  const recs = data.records.items;
  const recRows = recs.map((r) => `<tr><td>${esc(dmy(r.at))} ${esc(r.at.slice(11))}</td><td>${esc(r.type)}</td><td>${esc(r.ref || "—")}</td><td>${esc(r.detail || "—")}</td></tr>`).join("");
  const fbRows = data.feedback.map((f) => `<tr><td><b>${esc(periodText(f.period_start, f.period_end))}</b><br>${esc(f.remarks)}</td><td>${f.rating == null ? "—" : `${one(f.rating)} ${esc(f.rating_label)}`}</td>
    <td>${esc(dmy(f.published_at))}<br><small>${f.reviewer ? `${esc(f.reviewer.name)} (${esc(ROLE_LABEL[f.reviewer.role] || f.reviewer.role)})` : "Reviewer not recorded"}</small></td></tr>`).join("");
  const section = (title, note, head, rows, empty) => `<h2>${esc(title)}</h2>${note ? `<div class="note">${note}</div>` : ""}${rows
    ? `<table class="list"><thead><tr>${head.map((h) => `<th>${esc(h)}</th>`).join("")}</tr></thead><tbody>${rows}</tbody></table>`
    : `<div class="empty">${esc(empty)}</div>`}`;

  const latest = data.latest && data.latest.rating != null
    ? `${one(data.latest.rating)} ${esc(data.latest.rating_label)} · cutoff ${esc(periodText(data.latest.period_start, data.latest.period_end))}${data.latest.source === "recalculated" ? ` ${flag}` : ""}`
    : "No rating yet";

  const html = `<!doctype html><html><head><meta charset="utf-8"><title>Performance history ${esc(emp.code)}</title><style>${PRINT_BASE_CSS}
    body { font-family: Arial, Helvetica, sans-serif; color: #111827; font-size: 12px; }
    .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0e7490; padding-bottom: 8px; }
    .head h1 { margin: 0; font-size: 18px; letter-spacing: 2px; }
    .head .co { font-size: 11px; color: #374151; }
    .who { display: flex; gap: 14px; align-items: flex-start; margin-top: 12px; }
    .photo { width: 1.1in; height: 1.1in; flex-shrink: 0; border: 1px solid #9ca3af; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; color: #155e75; overflow: hidden; }
    .photo img { width: 100%; height: 100%; object-fit: cover; }
    .who .name { font-size: 17px; font-weight: 700; }
    .who .meta { margin-top: 4px; color: #374151; line-height: 1.6; }
    h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; background: #ecfeff; color: #155e75; padding: 4px 6px; margin: 14px 0 4px; break-after: avoid; }
    .note { font-size: 10.5px; color: #6b7280; margin: 0 0 4px 2px; }
    table.list th, table.list td { border: 1px solid #d1d5db; padding: 3px 6px; text-align: center; vertical-align: middle; }
    table.list th { background: #f3f4f6; }
    table.list td small { color: #6b7280; }
    .flag { font-size: 9.5px; font-weight: 700; background: #fef3c7; color: #92400e; padding: 0 5px; border-radius: 8px; }
    .bar { height: 8px; border-radius: 8px; background: #e5e7eb; overflow: hidden; margin: 0 auto 2px; width: 110px; }
    .bar span { display: block; height: 100%; background: #0891b2; }
    .empty { padding: 4px 6px; color: #6b7280; }
    .foot { margin-top: 18px; font-size: 9.5px; color: #6b7280; }
  </style></head><body><div class="sheet">
    <div class="head">
      <div><h1>EMPLOYEE PERFORMANCE HISTORY</h1><div class="co">${esc(COMPANY?.name || "Bella Erin Tube Ice Inc.")}</div></div>
      <div class="title" style="text-align:right"><div><b>${esc(emp.code)}</b></div><div class="co">Printed ${esc(dmy(new Date().toISOString()))}</div></div>
    </div>
    <div class="who">
      <div class="photo">${photo ? `<img src="${photo}" alt="">` : esc((emp.name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase())}</div>
      <div>
        <div class="name">${esc(emp.name)}</div>
        <div class="meta">${esc(emp.position || "")}${emp.department ? ` · ${esc(emp.department)}` : ""}${emp.date_hired ? `<br>Hired ${esc(dmy(emp.date_hired))}` : ""}${emp.shift ? ` · Shift ${esc(emp.shift)}` : ""}<br>
          Latest published rating: <b>${latest}</b><br>
          Shown ${by === "month" ? "monthly" : "quarterly"} · ${esc(cover)}</div>
      </div>
    </div>
    <h2>Performance history trend</h2>
    <div class="note">Average of the published cutoff ratings in each ${unitWord} (1 to 5).${recalc ? " “Recalculated” values were worked out from the records that exist now because the review was published before ratings were saved with it." : ""}</div>
    ${data.counts.published ? chartSvg(data.trend) : '<div class="empty">No published ratings in this date range.</div>'}
    ${data.counts.published ? `<table class="list"><thead><tr><th>${unit}</th><th>Average rating</th><th>Published cutoffs</th><th>Cutoff ratings</th></tr></thead><tbody>${trendRows}</tbody></table>` : ""}
    ${section("Attendance history", `Days present against expected work days, by ${unitWord}. Holidays and approved leave are not expected days. A dash means no expected work days.`, [unit, "Present", "Absent", "Attendance rate", "On time"], attRows, "")}
    ${data.goals.applicable
      ? section("Goal completion progress", `Counted in ${esc(data.goals.unit)}. Work above a cutoff's own goal is not counted toward the ${unitWord}. A dash means no published cutoff with a goal. Source: ${esc(data.goals.source)}`, [unit, "Done / goal", "Progress"], goalRows, "")
      : section("Goal completion progress", "", [], "", "No goal assigned.")}
    ${data.goals.applicable ? section("Work records", `Live from the source records${data.records.truncated ? "; only the newest 200 are listed" : ""}. They can differ from a published snapshot if records changed after publishing.`, ["Date", "Type", "Reference", "Details"], recRows, "No work records in this date range.") : ""}
    ${section("Review feedback", "Remarks from published performance reviews, newest first.", ["Feedback", "Rating", "Published"], fbRows, "No review feedback in this date range.")}
    <div class="foot">Confidential. Performance data is processed under the Data Privacy Act of 2012 (RA 10173) for employment purposes only.</div>
  </div></body></html>`;

  printDocument(html, docFileName("performance-history", emp.code, [emp.name].filter(Boolean).join("-"), `${data.range.from}-to-${data.range.to}`));
}
