import { useNavigate } from "react-router-dom";
import { Icon } from "./icons";

const CalendarIcon = () => <Icon name="calendar" size={13} />;

// The one Leave Request entry point for every role that files its own leave
// (Checker, Maintenance, Salesman, Driver). It always sits in the top bar,
// directly left of the Log Out button.
// compact: the icon only, a 44px square (the role screens' 56px header).
export default function LeaveRequestButton({ compact = false, variant = "icon", onActivate }) {
  const navigate = useNavigate();
  if (variant === "menu") {
    return (
      <button
        type="button"
        role="menuitem"
        onClick={() => { onActivate?.(); navigate("/my-leave"); }}
        className="profile-item w-full flex items-center gap-3 min-h-[48px] px-3 text-[14px] font-medium text-slate-700 text-left cursor-pointer"
      >
        <CalendarIcon />
        <span className="flex-1 min-w-0 truncate">Leave Request</span>
      </button>
    );
  }
  return (
    <button
      type="button"
      onClick={() => navigate("/my-leave")}
      title="Leave Request"
      aria-label="Leave Request"
      className={compact
        ? "flex items-center justify-center min-h-[44px] min-w-[44px] bg-slate-100 border border-black/[0.08] rounded-xl text-slate-600 active:scale-[0.98] transition-transform cursor-pointer"
        : "flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 border border-black/[0.08] rounded-lg text-slate-600 text-[11px] font-medium active:bg-slate-200 transition-colors cursor-pointer"}
    >
      <CalendarIcon />
      {/* On very small phones (under 360px) only the icon shows, so it stays beside the name */}
      {!compact && <span className="hidden min-[360px]:inline">Leave Request</span>}
    </button>
  );
}
