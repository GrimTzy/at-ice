import { useEffect } from "react";

// A phone notice about one record (e.g. "delivery:12") opens that record on its mobile page.
// The page marks each row with data-record="<kind>:<id>" on the element that opens it. Pages load
// their lists after they are shown, so the row is looked for until it appears (about 6 seconds).
// If the record is not in the list (removed, or not this person's), nothing opens.

const KEY = "open_record";
const EVENT = "atice:open-record";
const TRIES = 20;
const EVERY_MS = 300;

// Called by lib/push.js when a notice is tapped on a page that is already open.
export function requestNoticeRecord(record) {
  if (!record) return;
  try { sessionStorage.setItem(KEY, record); } catch { /* ignore */ }
  window.dispatchEvent(new Event(EVENT));
}

function pendingRecord() {
  try { return sessionStorage.getItem(KEY) || ""; } catch { return ""; }
}

function forgetRecord() {
  try { sessionStorage.removeItem(KEY); } catch { /* ignore */ }
}

// Pages call this once their screen is mounted. Every page of the mobile app calls it.
export function useNoticeRecord(ready = true) {
  useEffect(() => {
    if (!ready) return undefined;
    let timer = null;
    let tries = 0;
    let active = true;

    const attempt = () => {
      timer = null;
      if (!active) return;
      const record = pendingRecord();
      if (!record) return;
      const el = document.querySelector(`[data-record="${CSS.escape(record)}"]`);
      if (!el) {
        if (tries++ < TRIES) timer = setTimeout(attempt, EVERY_MS);
        return;
      }
      forgetRecord();
      el.scrollIntoView({ block: "center", behavior: "smooth" });
      // data-record-scroll: only show the row (its own buttons would change data, e.g. resolve)
      if (!el.hasAttribute("data-record-scroll")) el.click();
    };

    const again = () => {
      tries = 0;
      if (timer) clearTimeout(timer);
      attempt();
    };

    attempt();
    window.addEventListener(EVENT, again);
    return () => {
      active = false;
      if (timer) clearTimeout(timer);
      window.removeEventListener(EVENT, again);
    };
  }, [ready]);
}
