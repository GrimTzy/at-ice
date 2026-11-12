// src/pages/hr/HolidaysPage.jsx
//
// Holiday calendar (HR and Admin; R3 in docs/REVIEW-DECISIONS.md). Attendance,
// leave and payroll read holidays from here, so keep it current: holidays
// change every year and can be declared on short notice. The 2026 list is
// seeded from Proclamation No. 1006 and No. 1189 (verify against the latest).
// Adding and editing happen in a modal (the "Add holiday" button); the bar
// above the table only filters the list. A holiday is one date (owner,
// 2026-11-12: Proclamation No. 1006 lists every holiday as a single date);
// a declared multi-day event is added one day at a time as Special
// (non-working) days with the same name.
// Dates (owner, 2026-11-12): a new holiday is today or later (Manila), and a
// holiday dated before today has no Edit or Remove (attendance and payroll
// already counted it; the server refuses too). "Generate 2027 holidays" opens
// a preview of next year's repeating holidays (NextYearModal); nothing is
// saved until HR presses Save.

import { useCallback, useEffect, useMemo, useState } from "react";
import HrLayout from "../../components/HrLayout";
import AdminLayout from "../../components/AdminLayout";
import { SkeletonList } from "../../components/Skeleton";
import { toast } from "../../lib/toast";
import { useDraft } from "../../lib/useDraft";
import DraftHint from "../../components/DraftHint";
import Pagination from "../../components/Pagination";
import { usePagination } from "../../lib/usePagination";
import { confirmAction } from "../../lib/confirm";
import { KpiCard, KpiGrid } from "../../components/Kpi";
import { ActivityFeed, MiniBars, StatusBreakdown } from "../../components/Insight";
import { todayManila } from "../../lib/time";
import { Icon } from "../../components/icons";
import Req from "../../components/Req";
import { NameHelp } from "../../components/FieldError";
import { LIMITS, SHORT_WORD, nameError } from "../../lib/textRules";
import { holidayNameCheck } from "../../lib/holidayNames";

const token = () => localStorage.getItem("token") || "";
async function api(url, options = {}) {
  const res = await fetch(url, { ...options, headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token()}` } });
  const data = await res.json().catch(() => null);
  if (!res.ok) {
    const err = new Error(data?.errors ? Object.values(data.errors).flat()[0] : data?.message || `Request failed (${res.status})`);
    err.field = data?.errors ? Object.keys(data.errors)[0] : null; // shown under that field
    throw err;
  }
  return data;
}

const TYPES = [
  ["REGULAR", "Regular holiday", "bg-red-50 text-red-700 border-red-200"],
  ["SPECIAL_NON_WORKING", "Special (non-working)", "bg-amber-50 text-amber-700 border-amber-200"],
  ["SPECIAL_WORKING", "Special working day", "bg-slate-50 text-slate-600 border-slate-200"],
];
const typeOf = (t) => TYPES.find(([k]) => k === t) || [t, t, "bg-slate-50 text-slate-600 border-slate-200"];
const inputCls = "px-2.5 py-1.5 border border-slate-300 rounded-md text-xs text-slate-700 bg-white focus:outline-none focus:border-cyan-400";
const labelCls = "text-[10px] font-semibold uppercase tracking-wider text-slate-500";
const EMPTY = { date: "", name: "", type: "REGULAR", is_double: false, repeats_yearly: true };
const PAST_DATE = "Holiday date must be today or later.";
const NO_FILTER = { q: "", month: "", date: "", type: "" };
const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const fmt = (d) => new Date(`${d}T00:00:00`).toLocaleDateString("en-PH", { weekday: "short", month: "short", day: "numeric", year: "numeric" });

const SearchIcon = () => <Icon name="search" size={14} />;

function HolidayModal({ holiday, onClose, onSaved }) {
  const editing = holiday?.id || null;
  // Unsaved inputs survive a refresh (per holiday); closing forgets them.
  const draft = useDraft(`holidays:form:${editing || "new"}`, {
    form: holiday ? { date: holiday.date, name: holiday.name, type: holiday.type, is_double: !!holiday.is_double, repeats_yearly: holiday.repeats_yearly !== false } : EMPTY,
  });
  const { form } = draft.values;
  const setForm = draft.set.form;
  const today = todayManila();
  // a new date (or a changed one) must be today or later
  const dateError = form.date && form.date < today && form.date !== holiday?.date ? PAST_DATE : "";
  const [busy, setBusy] = useState(false);
  // the server's message for the name (e.g. already a holiday this year), until it is changed
  const [serverNameError, setServerNameError] = useState("");
  // The other holidays of the year being added to, to catch a repeated name
  // while typing (lib/holidayNames.js): a sure repeat is refused (red), a
  // name 1 or 2 letters away gets a soft "Did you mean" note.
  const year = String(form.date || todayManila()).slice(0, 4);
  const [yearList, setYearList] = useState({ year: null, holidays: [] });
  useEffect(() => {
    let cancelled = false;
    api(`/api/holidays?year=${year}`).then((d) => { if (!cancelled) setYearList({ year, holidays: d?.holidays || [] }); }).catch(() => {});
    return () => { cancelled = true; };
  }, [year]);
  // the holiday being edited is not "another" holiday
  const others = yearList.year === year ? yearList.holidays.filter((h) => h.id !== editing) : [];
  const repeat = holidayNameCheck(form.name, others, year, form.date, form.type);
  const nameMsg = nameError(form.name, "name", LIMITS.HOLIDAY, "Holiday name", SHORT_WORD) || serverNameError || repeat.block || "";
  const { clear } = draft;
  const close = useCallback(() => { clear(); onClose(); }, [clear, onClose]);
  // One holiday per date: the form says so before saving (the server refuses too)
  const taken = form.date ? others.find((h) => h.date === form.date) : null;
  const takenMsg = taken ? `${taken.name} is already on this date. Edit it instead.` : "";

  useEffect(() => {
    const onKey = (e) => { if (e.key === "Escape") close(); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [close]);

  const save = async (e) => {
    e.preventDefault();
    if (dateError) return toast.error(dateError);
    if (takenMsg || nameMsg) return;
    setBusy(true);
    try {
      // a draft saved before holidays became one date may still have end_date
      const { end_date: _end, ...body } = form;
      const res = await api(editing ? `/api/holidays/${editing}` : "/api/holidays", { method: editing ? "PUT" : "POST", body: JSON.stringify(body) });
      toast.success(editing ? `${form.name} was updated.` : res?.message || `${form.name} was added.`);
      draft.clear();
      onSaved(form.date);
    } catch (err) {
      if (err.field === "name") setServerNameError(err.message);
      else toast.error(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" onClick={close}>
      <form role="dialog" aria-modal="true" aria-label={editing ? "Edit holiday" : "Add a holiday"} onSubmit={save} onClick={(e) => e.stopPropagation()}
        className="modal-shell bg-white rounded-xl w-full max-w-md max-h-[90vh] flex flex-col overflow-hidden">
        <div className="shrink-0 px-5 py-4 border-b border-black/[0.09]">
          <div className="text-[15px] font-semibold text-slate-800">{editing ? "Edit holiday" : "Add a holiday"}</div>
          <div className="text-[11px] text-slate-500 mt-0.5">Use the date and name from the official proclamation.</div>
        </div>
        <div className="modal-body flex-1 min-h-0 overflow-y-auto overscroll-contain">
        <div className="p-5 flex flex-col gap-3.5">
        <DraftHint draft={draft} onDiscard={onClose} />
        <label className="flex flex-col gap-1"><span className="text-[11px] font-medium text-slate-600">Date<Req /></span>
          <input name="date" type="date" required autoFocus min={today} value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })}
            aria-invalid={dateError || takenMsg ? true : undefined} className={inputCls} />
          {(dateError || takenMsg) && <span role="alert" className="text-[11px] text-red-600">{dateError || takenMsg}</span>}</label>
        <label className="flex flex-col gap-1"><span className="text-[11px] font-medium text-slate-600">Holiday name<Req /></span>
          <input name="name" required maxLength={LIMITS.HOLIDAY} value={form.name} onChange={(e) => { setForm({ ...form, name: e.target.value }); setServerNameError(""); }} placeholder="e.g. Eid'l Adha"
            aria-invalid={nameMsg ? true : undefined} aria-describedby={nameMsg ? "holiday-name-error" : undefined} className={inputCls} />
          <NameHelp id="holiday-name-error" value={form.name} max={LIMITS.HOLIDAY} error={nameMsg} />
          {!nameMsg && repeat.warn && (
            <p role="status" className="mt-0.5 px-2.5 py-1.5 rounded-md bg-amber-50 border border-amber-200 text-[11.5px] leading-snug text-amber-800 break-words">{repeat.warn}</p>
          )}</label>
        <label className="flex flex-col gap-1"><span className="text-[11px] font-medium text-slate-600">Type</span>
          <select name="type" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} className={inputCls}>
            {TYPES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
          </select></label>
        <label className="flex items-start gap-2 text-xs text-slate-700">
          <input name="repeats_yearly" type="checkbox" data-native className="mt-0.5" checked={form.repeats_yearly !== false} onChange={(e) => setForm({ ...form, repeats_yearly: e.target.checked })} />
          <span>Repeats every year
            <span className="block text-[11px] text-slate-500">Untick for a one-time declared day. Only repeating holidays are carried over by Generate {Number(today.slice(0, 4)) + 1} holidays.</span></span>
        </label>
        </div>
        </div>
        <div className="shrink-0 px-5 py-3 border-t border-black/[0.09] flex justify-end gap-2">
          <button type="button" onClick={close} className="px-3.5 py-1.5 rounded-md border border-black/[0.09] text-xs text-slate-600 hover:bg-slate-50 cursor-pointer">Cancel</button>
          <button type="submit" disabled={busy} className="px-3.5 py-1.5 rounded-md bg-cyan-500 hover:bg-cyan-600 text-white text-xs font-semibold disabled:opacity-50 cursor-pointer">
            {busy ? "Saving…" : editing ? "Save changes" : "Add holiday"}
          </button>
        </div>
      </form>
    </div>
  );
}

const RULE_LABEL = { fixed: "Same date", easter: "From Easter", heroes: "Last Monday of August", moving: "Moving date" };

// Next year's holidays (App\Support\HolidayYear on the server): a preview of
// the holidays marked "Repeats every year". HR can change any date or untick
// any row; rows already in next year are shown and skipped. Nothing is saved
// until Save.
function NextYearModal({ onClose, onSaved }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState("");
  const [rows, setRows] = useState([]);
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState({ index: null, message: "" });

  useEffect(() => {
    api("/api/holidays/next-year").then((d) => {
      setData(d);
      // ticked when it has a date and is not skipped; moving ones are ticked once a date is set
      setRows(d.rows.map((r) => ({ ...r, picked: !r.skip && !!r.date })));
    }).catch((e) => setError(e.message));
  }, []);

  useEffect(() => {
    const onKey = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  const year = data?.year;
  const setDate = (i, date) => setRows((rs) => rs.map((r, j) => (j !== i ? r : {
    ...r, date, picked: date ? true : r.picked,
  })));
  const toggle = (i) => setRows((rs) => rs.map((r, j) => (j === i ? { ...r, picked: !r.picked } : r)));

  // problems with a ticked row, shown under it
  const problem = (r, i) => {
    if (!r.picked) return "";
    if (!r.date) return "Set the date, or untick this holiday.";
    if (String(r.date).slice(0, 4) !== String(year)) return `The date must be in ${year}.`;
    const clash = rows.findIndex((o, j) => j !== i && o.picked && o.date && o.date === r.date);
    if (clash !== -1) return `${rows[clash].name} is on the same date. Change one date.`;
    return "";
  };
  const picked = rows.filter((r) => r.picked);
  const problems = rows.map(problem);
  const blocked = problems.some(Boolean);

  const save = async () => {
    if (blocked || !picked.length) return;
    setBusy(true);
    setSaveError({ index: null, message: "" });
    try {
      const sent = rows.filter((r) => r.picked);
      const res = await api("/api/holidays/next-year", { method: "POST", body: JSON.stringify({
        holidays: sent.map((r) => ({ name: r.name, type: r.type, is_double: r.is_double, date: r.date })),
      }) });
      toast.success(res.message);
      onSaved(res.year);
    } catch (err) {
      // "holidays.3.date": point at that row
      const m = /^holidays\.(\d+)\./.exec(err.field || "");
      const sentIndex = m ? Number(m[1]) : null;
      const index = sentIndex === null ? null : rows.indexOf(rows.filter((r) => r.picked)[sentIndex]);
      setSaveError({ index, message: err.message });
      if (index === null) toast.error(err.message);
    } finally {
      setBusy(false);
    }
  };


  return (
    <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" onClick={onClose}>
      <div role="dialog" aria-modal="true" aria-label={`Generate ${year || ""} holidays`} onClick={(e) => e.stopPropagation()}
        className="modal-shell bg-white rounded-xl w-full max-w-3xl max-h-[90vh] flex flex-col overflow-hidden">
        <div className="shrink-0 px-5 py-4 border-b border-black/[0.09]">
          <div className="text-[15px] font-semibold text-slate-800">Generate {year || ""} holidays</div>
          <div className="text-[11px] text-slate-500 mt-0.5">
            From the {data?.from_year || ""} holidays marked &quot;Repeats every year&quot;. Check each date against the official proclamation, change any date or untick a row. Nothing is saved until you press Save.
          </div>
        </div>
        <div className="modal-body flex-1 min-h-0 overflow-y-auto overscroll-contain">
          {error ? <div className="px-5 py-8 text-center text-[12px] text-red-600">{error}</div>
            : !data ? <div className="p-5"><SkeletonList /></div>
            : rows.length === 0 ? <div className="px-5 py-8 text-center text-[12px] text-slate-400">No holidays in {data.from_year} are marked &quot;Repeats every year&quot;.</div>
            : (
            <table className="w-full text-xs">
              <thead><tr className="text-left text-slate-400 border-b border-black/[0.09]">
                <th className="pl-5 pr-2 py-2.5 font-medium w-8"><span className="sr-only">Add</span></th>
                <th className="px-2 py-2.5 font-medium">Holiday</th>
                <th className="px-2 py-2.5 font-medium">Date in {year}</th>
                <th className="px-2 pr-5 py-2.5 font-medium">How</th>
              </tr></thead>
              <tbody>
                {rows.map((r, i) => {
                  const skipped = r.skip === "duplicate";
                  const msg = problems[i] || (saveError.index === i ? saveError.message : "");
                  return (
                    <tr key={r.from} className={`border-b border-black/[0.06] last:border-b-0 align-top ${skipped ? "bg-slate-50 text-slate-400" : ""}`}>
                      <td className="pl-5 pr-2 py-2.5">
                        <input type="checkbox" data-native aria-label={`Add ${r.name}`} disabled={skipped} checked={!skipped && r.picked} onChange={() => toggle(i)} />
                      </td>
                      <td className="px-2 py-2.5">
                        <div className={`font-medium break-words ${skipped ? "" : "text-slate-800"}`}>{r.name}</div>
                        <div className="mt-0.5"><span className={`inline-block px-2 py-0.5 rounded-full border text-[10px] font-semibold ${typeOf(r.type)[2]}`}>{typeOf(r.type)[1]}</span></div>
                      </td>
                      <td className="px-2 py-2.5 whitespace-nowrap">
                        {skipped ? <span>—</span> : (
                          <input type="date" aria-label={`Date of ${r.name}`} value={r.date || ""} min={`${year}-01-01`} max={`${year}-12-31`}
                            onChange={(e) => setDate(i, e.target.value)} aria-invalid={msg ? true : undefined} className={inputCls} />
                        )}
                      </td>
                      <td className="px-2 pr-5 py-2.5">
                        <div className="text-slate-600">{RULE_LABEL[r.rule] || r.rule}</div>
                        <div className={`text-[11px] mt-0.5 ${r.skip === "taken" || r.rule === "moving" ? "text-amber-700" : "text-slate-500"}`}>{r.note}</div>
                        {msg && <div role="alert" className="text-[11px] text-red-600 mt-0.5">{msg}</div>}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </div>
        <div className="shrink-0 px-5 py-3 border-t border-black/[0.09] flex items-center justify-end gap-2">
          <span className="mr-auto text-[11px] text-slate-500">{picked.length} holiday{picked.length === 1 ? "" : "s"} will be added</span>
          <button type="button" onClick={onClose} className="px-3.5 py-1.5 rounded-md border border-black/[0.09] text-xs text-slate-600 hover:bg-slate-50 cursor-pointer">Cancel</button>
          <button type="button" onClick={save} disabled={busy || blocked || !picked.length} className="px-3.5 py-1.5 rounded-md bg-cyan-500 hover:bg-cyan-600 text-white text-xs font-semibold disabled:opacity-50 cursor-pointer">
            {busy ? "Saving…" : "Save"}
          </button>
        </div>
      </div>
    </div>
  );
}

export default function HolidaysPage({ admin = false }) {
  const Layout = admin ? AdminLayout : HrLayout;
  const [year, setYear] = useState(new Date().getFullYear());
  const [data, setData] = useState(null);
  const [error, setError] = useState("");
  // null, "add", or the holiday being edited; reopens after a refresh
  const modalDraft = useDraft("holidays:modal", { modal: null }, { when: (v) => !!v.modal });
  const { modal } = modalDraft.values;
  const setModal = modalDraft.set.modal;
  const [filter, setFilter] = useState(NO_FILTER);
  const [generating, setGenerating] = useState(false);
  const today = todayManila();
  const nextYear = Number(today.slice(0, 4)) + 1;

  const load = useCallback(() => {
    api(`/api/holidays?year=${year}`).then((d) => { setError(""); setData(d); }).catch((e) => setError(e.message));
  }, [year]);
  useEffect(() => { load(); }, [load]);

  const closeModal = useCallback(() => setModal(null), [setModal]);
  const saved = (date) => {
    setModal(null);
    if (date.slice(0, 4) !== String(year)) setYear(Number(date.slice(0, 4)));
    else load();
  };

  const remove = async (h) => {
    if (!(await confirmAction({ title: `Remove ${h.name}?`, message: `${h.date} will be an ordinary day again (late, absent and leave apply).`, confirmLabel: "Remove", danger: true }))) return;
    try {
      await api(`/api/holidays/${h.id}`, { method: "DELETE" });
      toast.success(`${h.name} was removed.`);
      load();
    } catch (err) {
      toast.error(err.message);
    }
  };

  const years = [...new Set([...(data?.years || []), new Date().getFullYear(), new Date().getFullYear() + 1])].sort();
  const filtering = Boolean(filter.q.trim() || filter.month || filter.date || filter.type);
  const shown = useMemo(() => {
    const q = filter.q.trim().toLowerCase();
    return (data?.holidays || []).filter((h) =>
      (!q || h.name.toLowerCase().includes(q))
      && (!filter.month || Number(h.date.slice(5, 7)) === Number(filter.month))
      && (!filter.date || h.date === filter.date)
      && (!filter.type || h.type === filter.type));
  }, [data, filter]);

  const pager = usePagination(shown);

  // Year summary for the KPI cards and side panels
  const all = data?.holidays || [];
  const todayStr = new Date().toLocaleDateString("en-CA"); // local YYYY-MM-DD
  const upcoming = all.filter((h) => h.date >= todayStr).sort((a, b) => a.date.localeCompare(b.date));
  const next = upcoming[0];
  const daysTo = next ? Math.round((new Date(`${next.date}T00:00:00`) - new Date(`${todayStr}T00:00:00`)) / 86400000) : null;
  const countType = (t) => all.filter((h) => h.type === t).length;
  const TYPE_TONE = { REGULAR: "rose", SPECIAL_NON_WORKING: "amber", SPECIAL_WORKING: "slate" };
  const byMonth = MONTHS.map((m, i) => ({ label: m.slice(0, 3), value: all.filter((h) => Number(h.date.slice(5, 7)) === i + 1).length }));

  return (
    <Layout pageTitle="Holidays" breadcrumb="HRIS / Holidays">
      <KpiGrid cols={5}>
        <KpiCard label={`Holidays in ${year}`} icon="calendar" tone="cyan" value={all.length} sub={`${upcoming.length} still to come`} bar={all.length ? upcoming.length / all.length : 0} />
        <KpiCard label="Regular" icon="star" tone="rose" value={countType("REGULAR")} sub="Double pay when worked" onClick={() => setFilter((f) => ({ ...f, type: f.type === "REGULAR" ? "" : "REGULAR" }))} active={filter.type === "REGULAR"} />
        <KpiCard label="Special (non-working)" icon="calendar" tone="amber" value={countType("SPECIAL_NON_WORKING")} sub="Premium pay when worked" onClick={() => setFilter((f) => ({ ...f, type: f.type === "SPECIAL_NON_WORKING" ? "" : "SPECIAL_NON_WORKING" }))} active={filter.type === "SPECIAL_NON_WORKING"} />
        <KpiCard label="Special working" icon="clock" tone="slate" value={countType("SPECIAL_WORKING")} sub="Ordinary work day" onClick={() => setFilter((f) => ({ ...f, type: f.type === "SPECIAL_WORKING" ? "" : "SPECIAL_WORKING" }))} active={filter.type === "SPECIAL_WORKING"} />
        <KpiCard label="Next holiday" icon="calendar" tone="emerald" value={next ? (daysTo === 0 ? "Today" : `${daysTo} day${daysTo === 1 ? "" : "s"}`) : "—"} sub={next ? `${next.name} · ${fmt(next.date)}` : `No more holidays in ${year}`} />
      </KpiGrid>

      <div className="grid grid-cols-1 xl:grid-cols-12 gap-4 items-start">
      <div className="xl:col-span-8 min-w-0">
      <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden">
        <div className="px-[18px] py-3.5 border-b border-black/[0.09] flex flex-wrap items-center gap-3">
          <div className="min-w-0">
            <div className="text-[15px] font-semibold text-slate-800">Holiday calendar</div>
            <div className="text-[11px] text-slate-500 mt-0.5">On these dates no one is marked absent or late, and leave cannot be filed. Pay follows Admin › Settings › Holiday pay.</div>
          </div>
          <div className="flex-1" />
          <button type="button" onClick={() => setGenerating(true)} className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-md border border-cyan-300 text-cyan-700 hover:bg-cyan-50 text-xs font-semibold cursor-pointer">
            Generate {nextYear} holidays
          </button>
          <button type="button" onClick={() => setModal("add")} className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-md bg-cyan-500 hover:bg-cyan-600 text-white text-xs font-semibold cursor-pointer">
            <span aria-hidden="true" className="text-[15px] leading-none">+</span> Add holiday
          </button>
        </div>

        <div role="search" aria-label="Find holidays" className="px-[18px] py-3 bg-slate-50 border-b border-black/[0.09] flex flex-wrap items-end gap-2.5">
          <label className="flex flex-col gap-1"><span className={labelCls}>Year</span>
            <select name="year" value={year} onChange={(e) => { setYear(Number(e.target.value)); setFilter((f) => ({ ...f, date: "" })); }} className={inputCls}>
              {years.map((y) => <option key={y} value={y}>{y}</option>)}
            </select></label>
          <label className="flex flex-col gap-1 flex-1 min-w-[180px]"><span className={labelCls}>Name</span>
            <span className="flex items-center gap-1.5 px-2.5 py-1.5 bg-white border border-slate-300 rounded-md text-slate-500 focus-within:border-cyan-400">
              <SearchIcon />
              <input name="q" type="search" value={filter.q} onChange={(e) => setFilter({ ...filter, q: e.target.value })} placeholder="Search holidays by name" className="w-full bg-transparent outline-none text-xs text-slate-700 placeholder:text-slate-500" />
            </span></label>
          <label className="flex flex-col gap-1"><span className={labelCls}>Month</span>
            <select name="month" value={filter.month} onChange={(e) => setFilter({ ...filter, month: e.target.value })} className={`${inputCls} min-w-[140px]`}>
              <option value="">All months</option>
              {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
            </select></label>
          <label className="flex flex-col gap-1"><span className={labelCls}>Date</span>
            <input name="date" type="date" value={filter.date} min={`${year}-01-01`} max={`${year}-12-31` < todayManila() ? `${year}-12-31` : todayManila()} onChange={(e) => setFilter({ ...filter, date: e.target.value })} className={inputCls} /></label>
          <label className="flex flex-col gap-1"><span className={labelCls}>Type</span>
            <select name="type" value={filter.type} onChange={(e) => setFilter({ ...filter, type: e.target.value })} className={inputCls}>
              <option value="">All types ({all.length})</option>
              {TYPES.map(([k, l]) => <option key={k} value={k}>{l} ({countType(k)})</option>)}
            </select></label>
          {filtering && <button type="button" onClick={() => setFilter(NO_FILTER)} className="clear-filters">Clear filters</button>}
        </div>

        {!data && !error ? <SkeletonList /> : error ? (
          <div className="px-[18px] py-8 text-center text-[12px] text-red-600">{error}</div>
        ) : data.holidays.length === 0 ? (
          <div className="px-[18px] py-8 text-center text-[12px] text-slate-400">No holidays for {year} yet. Use Add holiday to enter them from the official proclamation.</div>
        ) : shown.length === 0 ? (
          <div className="px-[18px] py-8 text-center text-[12px] text-slate-400">No holidays match these filters.</div>
        ) : (
          <table className="w-full text-xs">
            <thead><tr className="text-left text-slate-400 border-b border-black/[0.09]">
              <th className="px-[18px] py-2.5 font-medium">Date</th><th className="px-3 py-2.5 font-medium">Name</th>
              <th className="px-3 py-2.5 font-medium">Type</th><th className="px-3 py-2.5 font-medium text-right">Actions</th></tr></thead>
            <tbody>
              {pager.pageItems.map((h) => (
                <tr key={h.id} className="border-b border-black/[0.06] last:border-b-0 hover:bg-slate-50">
                  <td className="px-[18px] py-2.5 whitespace-nowrap text-slate-700">{fmt(h.date)}</td>
                  <td className="px-3 py-2.5 text-slate-800 font-medium max-w-[260px] break-words">{h.name}{h.is_double && <span className="cell-note text-[9px] font-bold text-red-600">DOUBLE</span>}</td>
                  <td className="px-3 py-2.5"><span className={`inline-block px-2 py-0.5 rounded-full border text-[10px] font-semibold ${typeOf(h.type)[2]}`}>{typeOf(h.type)[1]}</span></td>
                  <td className="px-3 py-2.5">
                    {h.date < today ? (
                      <div className="text-right text-[11px] text-slate-400" title="Holidays before today cannot be changed: attendance and payroll have already counted them.">Past</div>
                    ) : (
                    <div className="flex justify-end gap-1.5">
                      <button onClick={() => setModal(h)} className="px-2.5 py-1 border border-black/[0.09] rounded-md text-[11px] text-slate-600 cursor-pointer">Edit</button>
                      <button data-no-confirm onClick={() => remove(h)} className="px-2.5 py-1 border border-red-200 rounded-md text-[11px] text-red-600 cursor-pointer">Remove</button>
                    </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {data && shown.length > 0 && <Pagination pager={pager} label={filtering ? `holidays (filtered from ${data.holidays.length})` : "holidays"} />}
      </div>
      </div>
      <aside className="xl:col-span-4 flex flex-col gap-4 min-w-0">
        <ActivityFeed
          title="Coming up"
          items={upcoming.map((h) => ({ id: h.id, title: h.name, meta: typeOf(h.type)[1], when: fmt(h.date).replace(/, \d{4}$/, ""), tone: TYPE_TONE[h.type] || "slate", icon: "calendar" }))}
          empty={`No more holidays in ${year}.`}
          max={5}
        />
        <MiniBars title={`Holidays per month, ${year}`} data={byMonth} />
        <StatusBreakdown
          title="By type"
          items={TYPES.map(([k, l]) => ({ key: k, label: l, count: countType(k), tone: TYPE_TONE[k] }))}
          onPick={(i) => setFilter((f) => ({ ...f, type: f.type === i.key ? "" : i.key }))}
          active={filter.type}
          empty={`No holidays for ${year} yet.`}
        />
      </aside>
      </div>
      {modal && (modal === "add" || modal.date >= today) && <HolidayModal holiday={modal === "add" ? null : modal} onClose={closeModal} onSaved={saved} />}
      {generating && <NextYearModal onClose={() => setGenerating(false)} onSaved={(y) => { setGenerating(false); if (y !== year) setYear(y); else load(); }} />}
    </Layout>
  );
}
