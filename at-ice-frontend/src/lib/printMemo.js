import { PRINT_BASE_CSS, docFileName, letterhead, printDocument } from "./printDocument";
import { fullName } from "./names";

// The official memo letter (HR > Memos, Print): company letterhead, memo
// number, the employee, subject and text, and signature lines for the
// employee and HR. Same print base as the payslip, so "Save as PDF" in the
// print dialog makes the PDF copy.

const esc = (v) => String(v ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const date = (v) => (v ? new Date(`${String(v).slice(0, 10)}T00:00:00`).toLocaleDateString("en-PH", { month: "long", day: "numeric", year: "numeric" }) : "");

export function printMemo(memo) {
  const emp = memo.employee || {};
  const issued = memo.issued_at || memo.created_at;
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(memo.memo_no)}</title><style>
    ${PRINT_BASE_CSS}
    body { font-family: Arial, Helvetica, sans-serif; color: #0f172a; font-size: 13px; line-height: 1.55; }
    .head { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #0e7490; padding-bottom: 10px; }
    .head .muted { color: #475569; font-size: 11px; }
    .title { text-align: right; }
    .title h1 { margin: 0; font-size: 16px; letter-spacing: 2px; color: #0e7490; }
    .info { display: grid; grid-template-columns: 110px 1fr; gap: 4px 12px; margin: 16px 0; }
    .info b { color: #334155; }
    .body { white-space: pre-wrap; margin-top: 8px; }
    .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 56px; }
    .sign div { border-top: 1px solid #0f172a; padding-top: 4px; font-size: 11px; color: #334155; }
  </style></head><body><div class="sheet">
    <div class="head">
      ${letterhead()}
      <div class="title"><h1>MEMORANDUM</h1><div class="muted">${esc(memo.memo_no)}</div></div>
    </div>
    <div class="info">
      <b>To</b><span>${esc(fullName(emp) || emp.full_name || "")} (${esc(emp.employee_id || "")}) · ${esc(emp.position || "")}</span>
      <b>From</b><span>Human Resources</span>
      <b>Date</b><span>${esc(date(issued))}</span>
      <b>Incident date</b><span>${esc(date(memo.incident_date))}</span>
      <b>Subject</b><span>${esc(memo.subject)}</span>
    </div>
    <div class="body">${esc(memo.body)}</div>
    <div class="sign">
      <div>Received by (employee signature over printed name) / Date</div>
      <div>Human Resources</div>
    </div>
  </div></body></html>`;
  printDocument(html, docFileName("memo", memo.memo_no, emp.employee_id, String(issued || "").slice(0, 10)));
}

// An announcement for staff with no system account (Guards, Baggers): one
// signed copy per person, each on its own page.
export function printAnnouncementCopies(memo, employees) {
  const issued = memo.issued_at || memo.created_at;
  const sheets = employees.map((emp) => `<div class="sheet">
    <div class="head">
      ${letterhead()}
      <div class="title"><h1>ANNOUNCEMENT</h1><div class="muted">${esc(memo.memo_no)}</div></div>
    </div>
    <div class="info">
      <b>To</b><span>${esc(fullName(emp) || emp.full_name || "")} (${esc(emp.employee_id || "")}) · ${esc(emp.position || "")}</span>
      <b>From</b><span>Human Resources</span>
      <b>Date</b><span>${esc(date(issued))}</span>
      <b>Subject</b><span>${esc(memo.subject)}</span>
    </div>
    <div class="body">${esc(memo.body)}</div>
    <div class="sign">
      <div>Received by (employee signature over printed name) / Date</div>
      <div>Human Resources</div>
    </div>
  </div>`).join("");
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(memo.memo_no)}</title><style>
    ${PRINT_BASE_CSS}
    body { font-family: Arial, Helvetica, sans-serif; color: #0f172a; font-size: 13px; line-height: 1.55; }
    .sheet + .sheet { break-before: page; }
    .head { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #0e7490; padding-bottom: 10px; }
    .head .muted { color: #475569; font-size: 11px; }
    .title { text-align: right; }
    .title h1 { margin: 0; font-size: 16px; letter-spacing: 2px; color: #0e7490; }
    .info { display: grid; grid-template-columns: 110px 1fr; gap: 4px 12px; margin: 16px 0; }
    .info b { color: #334155; }
    .body { white-space: pre-wrap; margin-top: 8px; }
    .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 56px; }
    .sign div { border-top: 1px solid #0f172a; padding-top: 4px; font-size: 11px; color: #334155; }
  </style></head><body>${sheets}</body></html>`;
  printDocument(html, docFileName("announcement", memo.memo_no, "printed-copies", String(issued || "").slice(0, 10)));
}
