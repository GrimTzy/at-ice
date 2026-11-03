import { useEffect, useRef } from "react";

// "Are you sure you want to log out?" dialog. Opened through useLogoutConfirm.
export default function LogoutConfirmDialog({ open, onCancel, onConfirm }) {
  const cardRef = useRef(null);

  // The parent passes a new onCancel on every render; the effect must not restart
  // (and re-register its key listener) each time, so it reads the latest one here.
  const cancelRef = useRef(onCancel);
  useEffect(() => { cancelRef.current = onCancel; });

  // Esc closes; Tab stays between the two buttons. Giving focus back to whatever
  // opened the dialog is done by useLogoutConfirm (the Cancel button already took
  // focus by the time an effect here could ask which element had it).
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => {
      if (e.key === "Escape") return cancelRef.current();
      if (e.key !== "Tab") return undefined;
      const buttons = cardRef.current?.querySelectorAll("button");
      if (!buttons?.length) return undefined;
      const first = buttons[0];
      const last = buttons[buttons.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      return undefined;
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-slate-950/60" onClick={onCancel} />
      <div
        ref={cardRef}
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="logout-confirm-title"
        className="relative w-full max-w-[340px] bg-white border border-black/[0.09] rounded-xl shadow-2xl p-5 font-sans"
      >
        <h2 id="logout-confirm-title" className="text-[15px] font-semibold text-slate-800">Log out?</h2>
        <p className="text-[13px] text-slate-500 mt-1">Are you sure you want to log out?</p>
        <div className="flex justify-end gap-2 mt-5">
          <button
            type="button"
            autoFocus
            onClick={onCancel}
            className="px-3.5 py-1.5 rounded-md border border-black/[0.09] bg-white text-[13px] text-slate-600 hover:bg-slate-50 cursor-pointer"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={onConfirm}
            className="px-3.5 py-1.5 rounded-md bg-red-600 hover:bg-red-700 text-[13px] font-medium text-white cursor-pointer"
          >
            Log out
          </button>
        </div>
      </div>
    </div>
  );
}
