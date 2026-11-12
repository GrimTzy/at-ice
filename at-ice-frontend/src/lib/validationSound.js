import dripUrl from "../assets/sounds/validation.mp3";

// One sound for every validation message in the whole system, website and Android app:
// a notice (toast: saved, error, info) or an error box / field error that appears on a page.
// Nothing has to call this: it watches the page for those messages being added, so new
// forms get the sound too. The Attendance Station is left out (it has its own tones,
// lib/kioskSound.js).
//
// Browsers allow sound only after the user has touched the page; a validation message
// follows a click or a key press, so the sound is allowed by then.

const MESSAGE = '[role="alert"], .soft-toast, .field-error, [data-field-error]';
const GAP_MS = 600; // several messages at once (a form with many errors) sound once

let audio = null;
let lastPlayed = 0;

function play() {
  if (window.location.pathname.startsWith("/attendance-station")) return;
  const now = Date.now();
  if (now - lastPlayed < GAP_MS) return;
  lastPlayed = now;
  try {
    if (!audio) {
      audio = new Audio(dripUrl);
      audio.preload = "auto";
    }
    audio.currentTime = 0;
    audio.play().catch(() => { /* the page was not touched yet: stay silent */ });
  } catch {
    /* no audio on this device */
  }
}

// Many forms show their error as a small red line or box with no role. Such a line counts only
// when it is the thing that was just added (an error appearing under a field), never as part of
// a bigger piece of page that was drawn, so red badges and Delete buttons in a list stay silent.
const RED_TEXT = '[class*="text-red-5"], [class*="text-red-6"], [class*="text-red-7"], [class*="text-rose-6"], [class*="text-rose-7"]';
const isErrorLine = (node) =>
  (node.tagName === "DIV" || node.tagName === "P")
  && node.matches(RED_TEXT)
  && !node.matches(".table-status-badge")
  && !node.querySelector("button, a, input, select, textarea")
  && node.textContent.trim().length > 0;

const hasMessage = (node) =>
  node.nodeType === 1 && (node.matches?.(MESSAGE) || node.querySelector?.(MESSAGE) || isErrorLine(node));

export function installValidationSound() {
  if (typeof MutationObserver === "undefined" || !document.body) return;
  new MutationObserver((changes) => {
    for (const change of changes) {
      for (const node of change.addedNodes) {
        if (hasMessage(node)) return play();
      }
    }
  }).observe(document.body, { childList: true, subtree: true });
}
