import { useLightLock } from "../lib/theme";

// Keeps a page exactly as designed in light: the Night/Light choice never
// reaches it (login, attendance station, job application).
export default function LightOnly({ children }) {
  useLightLock();
  return children;
}
