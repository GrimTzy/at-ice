// The red * after the label of a required field. The field itself also
// carries `required`, so ActionGuard stops the form and scrolls to it when it
// is left empty, and controls.css rings it in red (light and night).
export default function Req() {
  return <span className="text-red-500" aria-hidden="true"> *</span>;
}
