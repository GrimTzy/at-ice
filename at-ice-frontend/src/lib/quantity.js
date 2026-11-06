// One rule for every quantity a person types (deliveries, contracts, stock
// movements, parts requests, loading counts): whole numbers only, never above
// the limit that applies to that field.
//
// The limit comes from the context: the item's stock on hand, the ordered
// quantity, or the per-delivery product limit (GET /api/limits). When nothing
// smaller applies, QTY_CEILING is the cap; it matches the backend's
// config('atice.max_stock_quantity').
export const QTY_CEILING = 9999;

// What the field should hold after a keystroke or paste: digits only, no
// leading zeros, and at most `max`. Empty stays empty so the field can be
// cleared while typing.
export function cleanQty(raw, max = QTY_CEILING) {
  const digits = String(raw ?? "").replace(/\D/g, "").replace(/^0+(?=\d)/, "").slice(0, 7);
  if (digits === "") return "";
  const cap = Number.isFinite(Number(max)) && Number(max) >= 0 ? Number(max) : QTY_CEILING;
  return String(Math.min(Number(digits), cap));
}

// True when cleanQty had to lower what was typed: the field shows a hint.
export function qtyWasCapped(raw, max = QTY_CEILING) {
  const digits = String(raw ?? "").replace(/\D/g, "");
  return digits !== "" && Number(digits) > Number(max);
}

// The props every quantity text field shares (numeric keypad on phones).
export const QTY_INPUT_PROPS = { type: "text", inputMode: "numeric", pattern: "[0-9]*", autoComplete: "off" };
