// Shared printing for the receipt, payslips and payroll slips.
//
// Works on any paper size and any margin setting in the print dialog:
//   - no fixed @page size, so the page follows the paper the user picks
//     (A4, Letter, Legal, A5, short bond, 80 mm receipt rolls...)
//   - @page margin 0 plus padding on .sheet, so the document keeps its own
//     white border even with "Margins: None" and is never cut at the edge
//   - narrow paper (A5 and smaller) stacks the two-column parts
//   - colors and the status stamp print without "Background graphics"
// Each document puts PRINT_BASE_CSS first in its <style> and its content in
// <div class="sheet">, and starts with letterhead() on the left of its header.
import { COMPANY } from "./company";
import printLogo from "../assets/print-logo.png";

// The Bella Erin Tube Ice logo on a clear background (no black box on paper).
// An absolute URL, since the print frame has no address of its own.
export const PRINT_LOGO_URL = new URL(printLogo, window.location.origin).href;
const LOGO_URL = PRINT_LOGO_URL;

const escHtml = (v) => String(v ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

// Letterhead used on every printout (business-letter order): logo at the top
// left, then the registered name, the address and the contacts. The document
// title (INVOICE, PAYSLIP...) goes on the right of the same header row.
// compact: logo and name only, for lists such as the attendance report.
export function letterhead({ compact = false } = {}) {
  return `<div class="brand">
    <img class="brand-logo" src="${LOGO_URL}" alt="Bella Erin Tube Ice">
    <div class="brand-text">
      <div class="brand-name">${escHtml(COMPANY.name)}</div>
      ${compact ? "" : `<div class="brand-sub">${escHtml(COMPANY.address)}<br>${escHtml(COMPANY.hotline)} · ${escHtml(COMPANY.email)}</div>`}
    </div>
  </div>`;
}

export const PRINT_BASE_CSS = `
  @page { margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { -webkit-print-color-adjust: exact; print-color-adjust: exact; overflow-wrap: anywhere; }
  .sheet { width: 100%; padding: 14mm 14mm 12mm; }
  table { width: 100%; border-collapse: collapse; }
  thead { display: table-header-group; }
  tr, .total, .net, .sign { break-inside: avoid; }
  .head { gap: 12px; }
  .head > div:first-child { min-width: 0; }
  .title { flex-shrink: 0; }

  /* Letterhead: logo 18 mm tall (about 34 mm wide), text beside it */
  .brand { display: flex; align-items: center; gap: 4mm; min-width: 0; }
  .brand-logo { height: 18mm; width: auto; flex-shrink: 0; display: block; }
  .brand-text { min-width: 0; }
  .brand-name { font-family: Arial, Helvetica, sans-serif; font-size: 15px; font-weight: 700; color: #0f172a; line-height: 1.25; }
  .brand-sub { font-family: Arial, Helvetica, sans-serif; font-size: 10px; color: #475569; line-height: 1.45; margin-top: 2px; max-width: 95mm; }

  /* A5 and other narrow paper: one column, smaller border */
  @media (max-width: 160mm) {
    .sheet { padding: 8mm 8mm 7mm; }
    body { font-size: 11px !important; }
    .head { flex-direction: column; }
    .title { text-align: left !important; }
    .info { grid-template-columns: 1fr !important; }
    .sign { grid-template-columns: 1fr !important; gap: 28px !important; }
    .brand-logo { height: 14mm; }
  }

  /* Receipt rolls (about 80 mm or less) */
  @media (max-width: 95mm) {
    .sheet { padding: 3mm; }
    body { font-size: 10px !important; }
    .title h1 { font-size: 14px !important; letter-spacing: 1px !important; }
    .info span { width: auto !important; margin-right: 6px; }
    th, td { padding: 3px 4px !important; }
    .brand { flex-direction: column; align-items: flex-start; gap: 2mm; }
    .brand-logo { height: 12mm; }
  }
`;

// One naming contract for every printout and export:
//   <doc-type>_<id-or-scope>_<date-or-cutoff>
// e.g. payslip_EMP-0001_CUTOFF-2026-10-26-to-2026-11-08. Empty parts are
// skipped; characters a file system rejects become "-".
export function docFileName(...parts) {
  return parts
    .map((p) => String(p ?? "").trim().replace(/[\\/:*?"<>|]+/g, "-").replace(/\s+/g, "-").replace(/-+/g, "-").replace(/^-|-$/g, ""))
    .filter(Boolean)
    .join("_");
}

export const cutoffPart = (start, end) => (start && end ? `CUTOFF-${String(start).slice(0, 10)}-to-${String(end).slice(0, 10)}` : "");

// The browser's "Save as PDF" names the file after the top page's title, so it
// is swapped for the document name while the print dialog is open.
export function setPrintTitle(fileName) {
  if (!fileName) return () => {};
  const previous = document.title;
  document.title = fileName;
  let restored = false;
  return () => {
    if (restored) return;
    restored = true;
    document.title = previous;
  };
}

// Prints a full HTML document from a hidden iframe: no popup window, and the
// page underneath is not affected. fileName pre-fills "Save as PDF".
export function printDocument(html, fileName) {
  const frame = document.createElement("iframe");
  Object.assign(frame.style, { position: "fixed", right: "0", bottom: "0", width: "0", height: "0", border: "0" });
  document.body.appendChild(frame);
  const doc = frame.contentWindow.document;
  doc.open();
  doc.write(html);
  doc.close();
  let restoreTitle = () => {};
  const cleanup = () => { restoreTitle(); setTimeout(() => frame.remove(), 1000); };
  frame.contentWindow.onafterprint = cleanup;
  // The logo and any photo must be loaded first, or they print blank
  whenImagesLoaded(doc).then(() => {
    restoreTitle = setPrintTitle(fileName);
    frame.contentWindow.focus();
    frame.contentWindow.print();
    setTimeout(cleanup, 60000); // fallback if onafterprint does not fire
  });
}

// Resolves once every image of the document has loaded or failed (at most 4 s).
export function whenImagesLoaded(doc) {
  const waits = [...doc.images].filter((img) => !img.complete).map((img) => new Promise((done) => {
    img.addEventListener("load", done, { once: true });
    img.addEventListener("error", done, { once: true });
  }));
  const timeout = new Promise((done) => setTimeout(done, 4000));
  return Promise.race([Promise.all(waits), timeout]).then(() => new Promise((done) => setTimeout(done, 100)));
}
