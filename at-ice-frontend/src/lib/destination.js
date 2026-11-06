// Where a card click lands, and the glow that shows it.
//
// A card either filters the page it is on (its own onClick) or opens another
// page (the `to` prop). Both end the same way: the list the card stands for is
// scrolled into view and pulses cyan for a moment. A page can name that list
// with data-dest="..."; otherwise it is the first table on the page.
//
// Cross-page: navigate(to, { state: { dest: true } }) (or a data-dest id).
// DestinationWatcher (in App) sees the state on arrival, waits for the list to
// render, then scrolls and pulses. The state is cleared from the history entry
// so a refresh or the back button does not replay it.

import { useEffect } from "react";
import { useLocation } from "react-router-dom";

const PULSE_MS = 450; // the lift is 400 ms; the attribute goes once it has ended

// The element that holds the records for a card: data-dest by id, or the card
// around the page's first table.
export function findDestination(id) {
  if (id && id !== true) {
    const named = document.querySelector(`[data-dest="${CSS.escape(String(id))}"]`);
    if (named) return named;
  }
  const marked = document.querySelector("main [data-dest]");
  if (marked) return marked;
  const table = document.querySelector("main table");
  return table ? table.closest(".rounded-xl, .insight-panel") || table : null;
}

// The element that scrolls the page around el: the layout's <main>
// (overflow-y: auto), or the document on pages that scroll as a whole.
function scrollerOf(el) {
  for (let n = el.parentElement; n && n !== document.body; n = n.parentElement) {
    const oy = getComputedStyle(n).overflowY;
    if ((oy === "auto" || oy === "scroll") && n.scrollHeight > n.clientHeight + 1) return n;
  }
  return document.scrollingElement || document.documentElement;
}

const SCROLL_MS = 480;
const easeOut = (t) => 1 - (1 - t) ** 3;

// Run fn once the element is in view. The page scrolls the element to the top
// of its scroll area (12px gap) unless it already sits in the upper third,
// then fn runs, so the animation never plays while the element is still
// sliding into view. The scroll is driven here frame by frame on the actual
// scroller, because smooth scrollIntoView does not move the layout's <main>.
export function runWhenInView(el, fn) {
  const scroller = scrollerOf(el);
  const isDoc = scroller === document.scrollingElement || scroller === document.documentElement;
  const box = isDoc ? { top: 0, height: window.innerHeight } : scroller.getBoundingClientRect();
  const offset = el.getBoundingClientRect().top - box.top;
  // already near the top of the scroll area: do not move the page
  if (offset >= 0 && offset <= box.height * 0.33) return fn();
  const from = scroller.scrollTop;
  const max = scroller.scrollHeight - scroller.clientHeight;
  const to = Math.max(0, Math.min(max, from + offset - 12));
  return glide(scroller, to, fn);
}

function glide(scroller, to, done) {
  const from = scroller.scrollTop;
  if (Math.abs(to - from) < 2) return done();
  const reduce = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
  // a hidden tab gets no animation frames: jump straight there
  if (reduce || document.hidden) {
    scroller.scrollTop = to;
    return done();
  }
  const start = performance.now();
  let frame;
  const step = (now) => {
    const t = Math.min(1, (now - start) / SCROLL_MS);
    scroller.scrollTop = from + (to - from) * easeOut(t);
    if (t < 1) frame = requestAnimationFrame(step);
    else done();
  };
  frame = requestAnimationFrame(step);
  return () => cancelAnimationFrame(frame);
}

// Where a list lands: the top of its table, so the column header sits this far
// under the page header and the first row comes right after it.
const LIST_GAP = 16;

// What to line up in a list: its table, or on phones the first card when the
// table is hidden. The table, not its thead, because a sticky thead moves.
function listAnchor(el) {
  if (el.matches("table")) return el;
  const shown = (n) => n.getClientRects().length > 0;
  return [...el.querySelectorAll("table")].find(shown) || [...el.querySelectorAll("[data-row]")].find(shown) || el;
}

// How much of the scroll area a sticky page header covers (phone screens, where
// the header scrolls with the page; on desktop it sits above the area).
function coveredTop(box) {
  let bottom = box.top;
  document.querySelectorAll("header").forEach((h) => {
    if (!["sticky", "fixed"].includes(getComputedStyle(h).position)) return;
    const r = h.getBoundingClientRect();
    if (r.top <= box.top + 1 && r.bottom > box.top) bottom = Math.max(bottom, r.bottom);
  });
  return bottom - box.top;
}

// Every card click lands the same way: the list moves up under the page header
// even when it is already on screen. A page too short to scroll that far stops
// at its end. Measured again at the end, in case the filter moved things.
function runAtListTop(el, fn) {
  const anchor = listAnchor(el);
  const scroller = scrollerOf(el);
  const isDoc = scroller === document.scrollingElement || scroller === document.documentElement;
  const away = () => {
    const box = isDoc ? { top: 0 } : scroller.getBoundingClientRect();
    return anchor.getBoundingClientRect().top - box.top - coveredTop(box) - LIST_GAP;
  };
  const max = scroller.scrollHeight - scroller.clientHeight;
  return glide(scroller, Math.max(0, Math.min(max, scroller.scrollTop + away())), () => {
    const off = away();
    if (Math.abs(off) >= 2) scroller.scrollTop += off;
    fn();
  });
}

// Pulse the element once. Calling it again restarts the pulse.
export function pulseDestination(el) {
  if (!el) return;
  return runAtListTop(el, () => {
    el.removeAttribute("data-dest-pulse");
    void el.offsetWidth; // restart the animation
    el.setAttribute("data-dest-pulse", "");
    window.clearTimeout(el._destTimer);
    el._destTimer = window.setTimeout(() => el.removeAttribute("data-dest-pulse"), PULSE_MS);
  });
}

// The record rows inside a destination: table body rows, or list items that
// mark themselves with data-row. The "no match" row of an empty table counts,
// so an empty result still answers the click.
const ROW_PULSE_MS = 400; // the row's entrance lasts 240 ms (glow.css fx-row-again)
const ROW_PULSE_MAX = 10;
export function rowsOf(el, rowKey) {
  if (!el) return [];
  if (rowKey != null) {
    const one = el.querySelector(`[data-row-key="${CSS.escape(String(rowKey))}"]`);
    return one ? [one] : [];
  }
  return [...el.querySelectorAll(":scope tbody > tr, :scope [data-row]")].slice(0, ROW_PULSE_MAX);
}

// Show the rows a card stands for, once the list is in view:
// - a filter card (no rowKey): the list now holds only the matching rows, so
//   the table's staggered cascade plays again on them (and only them);
// - one record (rowKey): only that row plays the entrance (glow.css
//   data-row-pulse), the rest of the table stays still.
// With no rows at all (not a table) the list itself pulses, as before.
export function pulseRows(el, rowKey, enter = false) {
  if (!el) return;
  const rows = rowsOf(el, rowKey);
  if (!rows.length) return pulseDestination(el);
  if (rowKey == null) {
    return runAtListTop(el, () => {
      if (enter) replayEnter(el);
      recascade(el);
    });
  }
  const row = rows[0];
  return runWhenInView(row, () => {
    row.removeAttribute("data-row-pulse");
    void row.offsetWidth; // restart on a repeat click
    row.setAttribute("data-row-pulse", "");
    window.clearTimeout(row._rowTimer);
    row._rowTimer = window.setTimeout(() => row.removeAttribute("data-row-pulse"), ROW_PULSE_MS);
  });
}

// Restart the staggered row cascade on a list (ui-effects/effects.css plays the
// same keyframes under the name a or b, so flipping it restarts every row).
export function recascade(el) {
  if (el) el.setAttribute("data-cascade", el.getAttribute("data-cascade") === "a" ? "b" : "a");
}

// Play the page-entrance animation (the one a menu click gives the content) on
// the whole list card again. Same a/b trick as recascade.
export function replayEnter(el) {
  if (el) el.setAttribute("data-enter", el.getAttribute("data-enter") === "a" ? "b" : "a");
}

// Same page: the filter's list is committed by React before the next frame, so
// on that frame scroll to the list and replay the cascade on the rows that are
// left, once they are on screen (so the user sees it). An empty result still
// cascades (its "no match" row is a row too). rowKey: only the row with that
// data-row-key plays (a single record).
export function focusDestination(id, rowKey) {
  requestAnimationFrame(() => pulseRows(findDestination(id), rowKey, true));
}

// One record: the page has already moved its pager to the record's page; wait
// for that row to render, then scroll to it and pulse it alone.
export function focusRecord(rowKey, id) {
  let tries = 0;
  const look = () => {
    const el = findDestination(id);
    if (el && rowsOf(el, rowKey).length) return pulseRows(el, rowKey);
    if (++tries < 20) requestAnimationFrame(look);
  };
  requestAnimationFrame(look);
}

// Other page: wait (up to 5 seconds) for the list to exist and have rows.
function waitForDestination(id, done) {
  const ready = () => {
    const el = findDestination(id);
    return el && !el.querySelector(".animate-pulse, [data-skeleton]") ? el : null;
  };
  const now = ready();
  let stopPulse;
  if (now) {
    stopPulse = done(now);
    return () => stopPulse?.();
  }
  const watch = new MutationObserver(() => {
    const el = ready();
    if (el) { cleanup(); stopPulse = done(el); }
  });
  const cleanup = () => { watch.disconnect(); window.clearTimeout(giveUp); stopPulse?.(); };
  const giveUp = window.setTimeout(cleanup, 5000);
  watch.observe(document.body, { childList: true, subtree: true });
  return cleanup;
}

export function DestinationWatcher() {
  const location = useLocation();
  const dest = location.state?.dest;

  useEffect(() => {
    if (!dest) return;
    const cancel = waitForDestination(dest, (el) => pulseRows(el));
    // drop the state from the history entry so a reload or back does not replay it
    const h = window.history.state;
    if (h?.usr) {
      const usr = { ...h.usr };
      delete usr.dest;
      window.history.replaceState({ ...h, usr: Object.keys(usr).length ? usr : null }, "");
    }
    return cancel;
  }, [dest, location.key]);

  return null;
}
