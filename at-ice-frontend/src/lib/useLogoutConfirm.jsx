import { useCallback, useRef, useState } from "react";
import LogoutConfirmDialog from "../components/LogoutConfirmDialog";
import { playSplash } from "./splash";

// Asks "Are you sure you want to log out?" before running onConfirm, then
// plays the splash while the session is cleared.
// Usage: const [askLogout, logoutDialog] = useLogoutConfirm(handleLogout);
// then call askLogout from the Log Out button and render {logoutDialog}.
export function useLogoutConfirm(onConfirm) {
  const [open, setOpen] = useState(false);
  const openerRef = useRef(null);
  const ask = useCallback(() => {
    openerRef.current = document.activeElement;
    setOpen(true);
  }, []);
  // closing gives focus back to the control that asked, if it is still on screen
  const cancel = () => {
    setOpen(false);
    const opener = openerRef.current;
    if (opener instanceof HTMLElement && opener.isConnected) requestAnimationFrame(() => opener.focus());
  };
  const dialog = (
    <LogoutConfirmDialog
      open={open}
      onCancel={cancel}
      onConfirm={() => {
        setOpen(false);
        playSplash();
        onConfirm();
      }}
    />
  );
  return [ask, dialog];
}

