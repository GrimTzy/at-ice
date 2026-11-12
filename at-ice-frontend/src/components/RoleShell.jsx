import { Link, useLocation } from "react-router-dom";
import { ROLE_NAV, ROLE_LABEL } from "../lib/mobileUi";
import { MemoAlert } from "./MyMemos";
import MobileHeader from "./MobileHeader";
import { Icon } from "./MobileUi";
import { pathOf, useNavCounts } from "../lib/navCounts";

// The one app shell of the Maintenance, Checker and Salesman screens (AT-ICE
// mobile contract): the shared MobileHeader (logo, title, role; Night/Light and
// the profile menu on the right), the page in a 16px-padded column, and a 64px
// bottom navigation with the role's tabs. `actionBar` is the page's main action,
// kept just above the navigation. `paged`: the page docks a pagination bar above
// the navigation (Pagination `dock`), so the list gets room under it. It sits
// inside NightScope (App.jsx), so the ice theme and night mode apply.
// `subHeader` (e.g. a search box) stays stuck under the title bar.
export default function RoleShell({ role, title, children, actionBar = null, headerAction = null, subHeader = null, paged = false }) {
  const location = useLocation();
  const counts = useNavCounts();
  const label = ROLE_LABEL[role] || role;
  const room = actionBar ? (paged ? "pb-56" : "pb-40") : paged ? "pb-36" : "pb-28";

  return (
    <div className={`role-shell min-h-[100dvh] w-full overflow-x-clip bg-slate-50 ${room}`}>
      <MobileHeader role={role} title={title} action={headerAction}>
        {subHeader && <div className="px-4 pb-3 max-w-md mx-auto md:max-w-none">{subHeader}</div>}
      </MobileHeader>

      <main className="p-4 space-y-4 max-w-md mx-auto md:max-w-none">
        <MemoAlert />
        {children}
      </main>

      {actionBar}

      <nav aria-label={`${label} sections`} className="role-nav fixed bottom-0 inset-x-0 z-50 h-16 bg-white border-t border-slate-200 flex justify-around items-center">
        {(ROLE_NAV[role] || []).map((tab) => {
          const active = tab.match ? tab.match(location) : location.pathname === tab.to;
          return (
            <Link
              key={tab.to}
              to={tab.to}
              aria-current={active ? "page" : undefined}
              className={`flex flex-1 flex-col items-center justify-center gap-0.5 min-h-[44px] min-w-[44px] h-full text-[11px] font-semibold active:scale-[0.98] transition-transform ${active ? "text-cyan-600" : "text-slate-400"}`}
            >
              <span className="relative">
                <Icon name={tab.icon} size={22} />
                {/* the waiting count of this tab (lib/navCounts.js), same as the bell */}
                {(Number(counts[pathOf(tab.to)]) || 0) > 0 && !tab.to.includes("?") && (
                  <span className="absolute -top-1.5 -right-3 min-w-[18px] h-[18px] px-1 rounded-full bg-blue-500 text-white text-[10.5px] font-bold leading-[18px] text-center"
                    aria-label={`${counts[pathOf(tab.to)]} waiting`}>
                    {counts[pathOf(tab.to)] > 99 ? "99+" : counts[pathOf(tab.to)]}
                  </span>
                )}
              </span>
              {tab.label}
            </Link>
          );
        })}
      </nav>
    </div>
  );
}
