// Length and character rules for text people type (owner, 2026-11-12). Same
// rules and messages as the server (App\Support\TextRules). Inputs get
// maxLength={LIMITS.X} so typing stops at the limit; <NameHelp> under a name
// field shows a live "38 / 50" counter and the message from nameError().
// Messages follow the GOV.UK pattern: name the field, say what is wrong and
// how to fix it.
export const LIMITS = {
  NAME: 40,     // route, product/item, supplier, position, chain
  HOLIDAY: 50,  // holiday (official names such as "Feast of the Immaculate Conception of Mary")
  PERSON: 40,   // first, middle, last name (each)
  FULL: 80,     // a whole person's name in one field (contact person, applicant, worker)
  STORE: 60,    // store or business name, contract name, school, company
  ADDRESS: 120, // one address line
  NOTE: 500,    // notes, reasons, remarks
  SUBJECT: 120, // memo subject
  REFERENCE: 50, // reference numbers
};

// Longest word: 15 in holiday, route, product/item, supplier and position
// names, 25 in the others.
export const SHORT_WORD = 15;
export const LONG_WORD = 25;

const NAME_CHARS = /^[\p{L}\p{N} .,'\-&()]+$/u;
const STORE_CHARS = /^[\p{L}\p{N} .,'\-&()/+]+$/u; // store and business names also allow / and +
const PERSON_CHARS = /^[\p{L} .'-]+$/u;
const FULL_NAME_CHARS = /^[\p{L} .,'-]+$/u; // a whole name in one field: "Dela Cruz, Juan"

const ALLOWED = {
  name: "letters, numbers, spaces, periods, commas, apostrophes, hyphens, ampersands (&) and brackets",
  store: "letters, numbers, spaces, periods, commas, apostrophes, hyphens, ampersands (&), brackets, slashes (/) and plus signs (+)",
  person: "letters, spaces, periods, hyphens and apostrophes",
  full: "letters, spaces, commas, periods, hyphens and apostrophes",
};

const KEY_ROWS = ["qwertyuiop", "asdfghjkl", "zxcvbnm"];

// +1 or -1 when b is the key right or left of a on one keyboard row, else 0
function keyStep(a, b) {
  for (const row of KEY_ROWS) {
    const i = row.indexOf(a);
    const j = row.indexOf(b);
    if (i >= 0 && j >= 0 && Math.abs(i - j) === 1) return j - i;
  }
  return 0;
}

// The longest run of keys side by side, in one direction, in a word
function keyRun(word) {
  let best = 1;
  let run = 1;
  let dir = 0;
  for (let i = 1; i < word.length; i++) {
    const step = keyStep(word[i - 1], word[i]);
    if (step !== 0 && step === dir) run += 1;
    else if (step !== 0) { run = 2; dir = step; } else { run = 1; dir = 0; }
    best = Math.max(best, run);
  }
  return best;
}

// Keyboard mashing (same rules as TextRules::looksMashed): a word over the
// longest-word limit; the same 2 to 4 letters 3 or more times in a row
// ("asdasdasd"); a word that is the same 3 or 4 letters twice with two keys
// side by side ("dasdas"); 4 or more keys side by side ("qwer", "asdf").
export function looksMashed(value, longestWord = LONG_WORD) {
  const words = String(value || "").toLowerCase().trim().split(/[\s\-/+,.()&]+/u).filter(Boolean);
  return words.some((w) => {
    if ([...w].length > longestWord) return true;
    if (/(\p{L}{2,4})\1{2,}/u.test(w)) return true;
    const twice = w.match(/^(\p{L}{3,4})\1$/u);
    if (twice && keyRun(twice[1]) >= 2) return true;
    return keyRun(w) >= 4;
  });
}

// The message for a name that breaks a rule, or "" (empty is left to "required").
//   kind "name"  : letters, numbers, spaces and . , ' - & ( )
//   kind "store" : the same plus / and + (store and business names)
//   kind "person": letters, spaces, periods, hyphens, apostrophes (and a comma
//                  in a whole name, when max is over LIMITS.PERSON)
// label: the field's name as shown on the page ("Holiday name").
// longestWord: SHORT_WORD for holiday, route, product/item, supplier and
// position names; LONG_WORD (default) for the others.
export function nameError(value, kind = "name", max = kind === "person" ? LIMITS.PERSON : LIMITS.NAME, label = "Name", longestWord = LONG_WORD) {
  const v = String(value ?? "").trim();
  if (!v) return "";
  if (v.length > max) return `${label} must be ${max} characters or fewer.`;
  const full = kind === "person" && max > LIMITS.PERSON;
  const chars = kind === "person" ? (full ? FULL_NAME_CHARS : PERSON_CHARS) : kind === "store" ? STORE_CHARS : NAME_CHARS;
  if (!chars.test(v)) return `${label} can only include ${ALLOWED[kind === "person" ? (full ? "full" : "person") : kind === "store" ? "store" : "name"]}.`;
  if (!/\p{L}/u.test(v)) return `${label} must include letters.`;
  if (looksMashed(v, longestWord)) return `Check the spelling of the ${label.toLowerCase()}. A word in it is too long or repeats the same letters.`;
  return "";
}
