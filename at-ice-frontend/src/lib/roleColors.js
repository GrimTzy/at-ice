// One color per role, position and shift, used everywhere they appear:
// Staff by role / Staff by position bars, the Employees table role badges,
// filter chips and detail views. A position takes the color of the role it
// logs in as, so Checker is cyan whether it is shown as a role or a position.
// Tone names are the ones in lib/kpiTones.js.

export const ROLE_TONE = {
  checker: "cyan",
  driver: "violet",
  maintenance: "amber",
  salesman: "emerald",
  guard: "blue",
  bagger: "orange",
  hr: "rose",
  admin: "slate",
  "admin staff": "slate",
};

export const SHIFT_TONE = { day: "amber", night: "violet" };

// "Checker", "checker", "Admin Staff" -> its tone; anything else is neutral.
export const roleTone = (key) => ROLE_TONE[String(key || "").trim().toLowerCase()] || "slate";

// Badge classes per tone. The chip is neumorphic (raised, no tint, no border);
// only the text carries the tone. The colors for each theme are in
// neumorphism.css (.neu-chip-*), so light and dark switch with every other surface.
const BADGE = {
  cyan: "neu-chip neu-chip-cyan",
  violet: "neu-chip neu-chip-violet",
  amber: "neu-chip neu-chip-amber",
  emerald: "neu-chip neu-chip-emerald",
  blue: "neu-chip neu-chip-blue",
  orange: "neu-chip neu-chip-orange",
  rose: "neu-chip neu-chip-rose",
  slate: "neu-chip neu-chip-slate",
};

export const roleBadge = (key) => BADGE[roleTone(key)];
