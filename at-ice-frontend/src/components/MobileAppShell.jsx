import "./mobile-app-shell.css";
import { useNightMode } from "../lib/theme";

// Frames the four mobile modules (Driver, Checker, Maintenance, Salesman) so
// they read as an app on a big screen. Under 768px it adds nothing (the page is
// the phone). From 768px it is a centered tablet column, from 1024px a phone
// frame. The frame is a containing block (see mobile-app-shell.css), so the
// pages' own `fixed` bars and buttons stay inside it. Wrap NightScope with it:
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
