import "./mobile-app-shell.css";
import { useNightMode } from "../lib/theme";

// Scope of the four mobile modules (Driver, Checker, Maintenance, Salesman).
// There is no phone frame: the page fills the window, and its content follows
// a column whose width is set per screen size (mobile-app-shell.css). Only
// pages wrapped in it get these rules. Wrap NightScope with it:
// <MobileAppShell><NightScope>...</NightScope></MobileAppShell>.
export default function MobileAppShell({ children }) {
  const [dark] = useNightMode();
  return (
    <div className="mas-backdrop" data-mas-dark={dark ? "true" : "false"}>
      <div className="mas-frame">
        <div className="mas-scroll">{children}</div>
      </div>
    </div>
  );
}
