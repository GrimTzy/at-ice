// When two holiday names are the same holiday (owner, 2026-11-12): block sure
// duplicates, warn on possible ones. Same rules as the server
// (App\Support\HolidayNames).
//   holidayKey: lower case, without . ' and with - , as spaces, single
//               spaces, a trailing "s" dropped from each word. The same key
//               as another holiday that year: saving is blocked.
//   isClose:    1 or 2 letters apart, for names of 8 or more letters: a soft
//               "Did you mean" note; saving is still allowed.
const MIN_LETTERS = 8;
const MAX_APART = 2;

export function holidayKey(name) {
  return String(name || "")
    .toLowerCase()
    .trim()
    .replace(/[.'’]/gu, "")
    .replace(/[-,]/gu, " ")
    .split(/\s+/u)
    .filter(Boolean)
    .map((w) => ([...w].length > 1 ? w.replace(/s$/u, "") : w))
    .join(" ");
}

const letters = (key) => (key.match(/\p{L}/gu) || []).length;

function distance(a, b) {
  const x = [...a];
  const y = [...b];
  if (Math.abs(x.length - y.length) > MAX_APART) return MAX_APART + 1;
  let prev = Array.from({ length: y.length + 1 }, (_, j) => j);
  for (let i = 0; i < x.length; i++) {
    const cur = [i + 1];
    for (let j = 0; j < y.length; j++) cur.push(Math.min(prev[j + 1] + 1, cur[j] + 1, prev[j] + (x[i] === y[j] ? 0 : 1)));
    prev = cur;
  }
  return prev[y.length];
}

export function isClose(a, b) {
  const ka = holidayKey(a);
  const kb = holidayKey(b);
  if (ka === kb || letters(ka) < MIN_LETTERS || letters(kb) < MIN_LETTERS) return false;
  return distance(ka, kb) <= MAX_APART;
}

// "Feb 17", or "Apr 10 to Apr 12" for a range
const day = (d) => new Date(`${String(d).slice(0, 10)}T00:00:00`).toLocaleDateString("en-US", { month: "short", day: "numeric" });
// The days of the first holiday with that name: its first date and the
// consecutive days after it (one range), not an older repeat later in the year.
function daysOf(rows) {
  const all = rows.map((h) => String(h.date).slice(0, 10)).sort();
  const dates = [all[0]];
  for (const d of all.slice(1)) {
    const next = new Date(`${dates[dates.length - 1]}T00:00:00`);
    next.setDate(next.getDate() + 1);
    const nextStr = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, "0")}-${String(next.getDate()).padStart(2, "0")}`;
    if (d !== nextStr) break;
    dates.push(d);
  }
  return dates.length > 2 ? `${day(dates[0])} to ${day(dates[dates.length - 1])}` : dates.map(day).join(", ");
}

// A declared multi-day event (e.g. APEC 2015, Nov 18 and 19): the same name
// may be used again on the day right before or after a holiday with that
// name when both are Special (non-working). Same as the server
// (HolidayController::nextToSpecial).
function shiftDay(date, n) {
  const x = new Date(`${String(date).slice(0, 10)}T00:00:00`);
  x.setDate(x.getDate() + n);
  return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, "0")}-${String(x.getDate()).padStart(2, "0")}`;
}
export function nextToSpecial(rows, date, type) {
  if (!date || type !== "SPECIAL_NON_WORKING") return false;
  const around = [shiftDay(date, -1), shiftDay(date, 1)];
  return rows.some((h) => h.type === "SPECIAL_NON_WORKING" && around.includes(String(h.date).slice(0, 10)));
}

// For the name being typed (on `date`, of `type`), against the other holidays of that year:
//   { block: message } a sure duplicate (red, saving is refused)
//   { warn: message }  a possible duplicate (soft note, saving is allowed)
//   {}                 neither
export function holidayNameCheck(name, holidays, year, date = "", type = "") {
  const key = holidayKey(name);
  if (!key) return {};
  const groups = new Map();
  for (const h of holidays) {
    const k = holidayKey(h.name);
    if (!groups.has(k)) groups.set(k, []);
    groups.get(k).push(h);
  }
  const same = groups.get(key);
  if (same && nextToSpecial(same, date, type)) return {};
  if (same) return { block: `"${same[0].name}" is already a holiday in ${year} (${daysOf(same)}). Edit that one instead.` };
  for (const rows of groups.values()) {
    if (isClose(name, rows[0].name)) return { warn: `Did you mean "${rows[0].name}" (${daysOf(rows)})? Saving is still allowed.` };
  }
  return {};
}
