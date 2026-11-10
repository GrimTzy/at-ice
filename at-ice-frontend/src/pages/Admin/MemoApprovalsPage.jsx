import { useCallback, useEffect, useState } from "react";
import AdminLayout from "../../components/AdminLayout";
import { SkeletonList } from "../../components/Skeleton";
import EmptyState from "../../components/EmptyState";
import Pagination from "../../components/Pagination";
import { usePagination } from "../../lib/usePagination";
import { KpiCard, KpiGrid } from "../../components/Kpi";
import { ActivityFeed, TipsWidget } from "../../components/Insight";
import { useLiveTick } from "../../lib/live";
import { formatDateShort, formatDateTime } from "../../lib/time";
import { openProtectedFile } from "../../lib/openFile";
import { fullName } from "../../lib/names";
import { toast } from "../../lib/toast";
import { MEMO_LEVEL } from "../../lib/memos";

// HRIS > Memo Approvals: formal memos (Final Written Warning, Notice to Explain,
// zero tolerance) that HR endorsed. Admin reads the letter and approves it, so
// HR can release it, or returns it to HR with a reason.

const authHeaders = (extra = {}) => ({
  Accept: "application/json",
  Authorization: `Bearer ${localStorage.getItem("token")}`,
  ...extra,
});

async function post(url, body) {
  const res = await fetch(url, {
    method: "POST",
    headers: authHeaders(body ? { "Content-Type": "application/json" } : {}),
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
    throw new Error(first || data.message || `The request failed (${res.status}).`);
  }
  return data;
}

const inputCls = "w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-md text-[13px] text-slate-700 outline-none focus:border-cyan-500/60";
const empLabel = (e) => (e ? `${e.employee_id} - ${fullName(e)}${e.position ? ` [${e.position}]` : ""}` : "Employee");

function LevelPill({ level }) {
  const s = MEMO_LEVEL[level] || { label: level, cls: "bg-slate-100 text-slate-600 border-slate-200" };
  return <span className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-medium border whitespace-nowrap ${s.cls}`}>{s.label}</span>;
}

function ApprovalPanel({ memo, onDone, onClose }) {
  const [returning, setReturning] = useState(false);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState("");
  const [error, setError] = useState("");

  useEffect(() => { setReturning(false); setReason(""); setError(""); }, [memo.id]);

  const act = async (key, url, body, done) => {
    setBusy(key);
    setError("");
    try {
      await post(url, body);
      toast.success(done);
      onDone();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy("");
    }
  };

  const sendBack = () => {
    if (reason.trim().length < 5) {
      setError("Write why the memo goes back to HR (at least 5 characters).");
      return;
    }
    act("return", `/api/memos/${memo.id}/return`, { reason: reason.trim() }, "Memo returned to HR.");
  };

  const attachments = (memo.files || []).filter((f) => f.kind === "attachment");

  return (
    <div className="bg-white border border-black/[0.09] rounded-xl overflow-hidden xl:sticky xl:top-0">
      <div className="px-5 py-3.5 border-b border-black/[0.09] flex flex-wrap items-center gap-2">
        <span className="font-mono text-[12px] font-semibold text-slate-800">{memo.memo_no}</span>
        <LevelPill level={memo.level} />
        <button type="button" data-no-confirm onClick={onClose} title="Close this panel" className="ml-auto px-2 py-1 rounded-md text-[12px] text-slate-500 hover:bg-slate-50 cursor-pointer">Close panel</button>
      </div>
      <div className="px-5 py-4 space-y-4">
        <div className="grid grid-cols-2 gap-x-4 gap-y-2.5 text-[12px]">
          <div className="col-span-2 min-w-0">
            <div className="text-[11px] text-slate-400">Employee</div>
            <div className="text-slate-800 font-medium">{empLabel(memo.employee)}</div>
          </div>
          <div><div className="text-[11px] text-slate-400">Violation</div><div className="text-slate-800">{memo.violation_label}</div></div>
          <div><div className="text-[11px] text-slate-400">Incident date</div><div className="text-slate-800">{formatDateShort(memo.incident_date, { year: true })}</div></div>
          <div className="col-span-2"><div className="text-[11px] text-slate-400">Endorsed by HR</div><div className="text-slate-800">{memo.endorser?.name || "HR"}{memo.endorsed_at ? ` · ${formatDateTime(memo.endorsed_at, { year: false })}` : ""}</div></div>
        </div>

        <div className="space-y-1.5">
          <div className="text-[11px] font-semibold text-slate-500 uppercase tracking-wide">Letter</div>
          <div className="text-[13px] font-medium text-slate-800">{memo.subject}</div>
          <div className="rounded-lg border border-black/[0.06] bg-slate-50 px-3 py-2 text-[12.5px] text-slate-700 whitespace-pre-wrap break-words shadow-inner max-h-80 overflow-y-auto">{memo.body}</div>
        </div>

        {attachments.length > 0 && (
          <div className="space-y-1.5">
            <div className="text-[11px] font-semibold text-slate-500 uppercase tracking-wide">Files</div>
            <ul className="flex flex-col gap-1">
              {attachments.map((f) => (
                <li key={f.id}>
                  <button type="button" data-no-confirm onClick={() => openProtectedFile(`/api/memos/${memo.id}/files/${f.id}`, f.original_name)} title="Open this file" className="min-w-0 truncate text-[12px] text-cyan-700 hover:underline cursor-pointer text-left">{f.original_name}</button>
                </li>
              ))}
            </ul>
          </div>
        )}

        {returning && (
          <div className="space-y-2 rounded-lg border border-amber-200 bg-amber-50/60 p-3">
            <div className="text-[12px] font-medium text-amber-800">Why does this memo go back to HR? (required)</div>
            <textarea name="return_reason" rows={2} value={reason} maxLength={1000} onChange={(e) => { setReason(e.target.value); setError(""); }} placeholder="e.g. Attach the supervisor's incident report first" className={`${inputCls} resize-y`} />
            <div className="flex flex-wrap gap-2">
              <button type="button" disabled={busy === "return"} onClick={sendBack} title="Send the memo back to HR as a draft" className="px-3 py-1.5 rounded-lg text-[12px] font-medium bg-amber-600 hover:bg-amber-700 text-white cursor-pointer disabled:opacity-50">
                {busy === "return" ? "Returning…" : "Return to HR"}
              </button>
              <button type="button" data-no-confirm onClick={() => { setReturning(false); setReason(""); setError(""); }} className="px-3 py-1.5 rounded-lg text-[12px] border border-black/[0.09] text-slate-600 hover:bg-slate-50 cursor-pointer">Back</button>
            </div>
          </div>
        )}

        {error && <div className="text-[12px] text-red-600">{error}</div>}

        {!returning && (
          <div className="flex flex-wrap gap-2 pt-3 border-t border-black/[0.06]">
            <button type="button" data-no-confirm onClick={() => setReturning(true)} title="Send it back to HR with a reason" className="px-3 py-1.5 rounded-lg text-[12px] font-medium border border-black/[0.09] text-amber-700 hover:bg-amber-50 cursor-pointer">Return to HR…</button>
            <button type="button" disabled={busy === "approve"} onClick={() => act("approve", `/api/memos/${memo.id}/approve`, null, "Memo approved. HR can now release it.")} title="Approve the memo; HR then releases it to the employee" className="ml-auto px-3 py-1.5 rounded-lg text-[12px] font-medium bg-cyan-500 hover:bg-cyan-600 text-white cursor-pointer disabled:opacity-50">
              {busy === "approve" ? "Approving…" : "Approve memo"}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

export default function MemoApprovalsPage() {
  const [data, setData] = useState({ memos: [], recent: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [selected, setSelected] = useState(null);

  const load = useCallback(async () => {
    try {
      const res = await fetch("/api/memo-approvals", { headers: authHeaders() });
      if (!res.ok) throw new Error(`Could not load memos (${res.status})`);
      setData(await res.json());
      setError("");
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  }, []);

  const live = useLiveTick(["memos"]);
  useEffect(() => { load(); }, [load, live]);

  const pager = usePagination(data.memos);
  const current = data.memos.find((m) => m.id === selected);
  const nte = data.memos.filter((m) => m.level === "nte").length;

  const recent = (data.recent || []).map((m) => ({
    id: m.id,
    title: `${m.memo_no} · ${fullName(m.employee || {})}`,
    meta: m.status === "draft" && m.returned_at ? `Returned by ${m.returner?.name || "Admin"}` : `Approved by ${m.approver?.name || "Admin"}`,
    when: formatDateTime(m.approved_at || m.returned_at, { year: false }),
    tone: m.status === "draft" ? "amber" : "emerald",
    icon: "file",
  }));

  return (
    <AdminLayout pageTitle="Memo Approvals" breadcrumb="HRIS / Memo Approvals">
      <KpiGrid cols={3}>
        <KpiCard label="Waiting for you" icon="clock" tone="amber" value={data.memos.length} sub="Endorsed by HR" live={data.memos.length > 0} />
        <KpiCard label="Notices to Explain" icon="alert" tone="rose" value={nte} sub="Open an investigation once released" />
        <KpiCard label="Final written" icon="file" tone="violet" value={data.memos.length - nte} sub="Final warnings and zero tolerance" />
      </KpiGrid>

      <div className="grid grid-cols-1 xl:grid-cols-12 gap-4 items-start">
        <div className="xl:col-span-7 min-w-0 bg-white border border-black/[0.09] rounded-xl overflow-hidden">
          <div className="px-5 py-3.5 border-b border-black/[0.09]">
            <div className="text-[13px] font-semibold text-slate-800">Waiting for approval</div>
            <div className="text-[11px] text-slate-400 mt-0.5">Formal memos HR endorsed. Pick one to read it, then approve it or return it to HR.</div>
          </div>
          {error && <div className="px-5 py-3 text-[12px] text-red-600 bg-red-50 border-b border-red-200">{error}</div>}
          {loading ? (
            <SkeletonList />
          ) : data.memos.length === 0 ? (
            <EmptyState icon="check" title="Nothing to approve">Formal memos HR endorses show up here.</EmptyState>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-[13px]">
                  <thead>
                    <tr className="text-slate-400 text-[11px] uppercase tracking-widest">
                      <th className="px-4 py-2.5 font-medium">Memo no.</th>
                      <th className="px-4 py-2.5 font-medium">Employee</th>
                      <th className="px-4 py-2.5 font-medium">Level</th>
                      <th className="px-4 py-2.5 font-medium">Endorsed</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pager.pageItems.map((m) => (
                      <tr
                        key={m.id}
                        onClick={() => setSelected(m.id)}
                        onKeyDown={(e) => (e.key === "Enter" || e.key === " ") && (e.preventDefault(), setSelected(m.id))}
                        tabIndex={0}
                        aria-selected={selected === m.id}
                        className={`border-t border-black/[0.06] cursor-pointer hover:bg-slate-50 ${selected === m.id ? "bg-cyan-50/60" : ""}`}
                      >
                        <td className="px-4 py-2.5 font-mono text-[12px] text-slate-700 whitespace-nowrap">{m.memo_no}</td>
                        <td className="px-4 py-2.5 text-slate-800">{fullName(m.employee || {})}<div className="text-[11px] text-slate-400">{m.violation_label}</div></td>
                        <td className="px-4 py-2.5"><LevelPill level={m.level} /></td>
                        <td className="px-4 py-2.5 text-slate-500 whitespace-nowrap">{formatDateTime(m.endorsed_at, { year: false })}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <Pagination pager={pager} label="memos" />
            </>
          )}
        </div>

        <aside className="xl:col-span-5 flex flex-col gap-4 min-w-0">
          {current ? (
            <ApprovalPanel memo={current} onDone={() => { setSelected(null); load(); }} onClose={() => setSelected(null)} />
          ) : (
            <>
              <ActivityFeed title="Recent decisions" items={recent} empty="Approved and returned memos show up here." max={6} />
              <TipsWidget tips={[
                { title: "Check the facts", text: "Open the attached reports and photos before approving a Notice to Explain.", tone: "amber", icon: "alert" },
                { title: "HR releases it", text: "An approved memo reaches the employee only when HR releases it.", tone: "cyan", icon: "file" },
              ]} />
            </>
          )}
        </aside>
      </div>
    </AdminLayout>
  );
}
