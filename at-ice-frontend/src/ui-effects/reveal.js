// Content that replaces a skeleton enters the way a new page does.
//
// The skeleton loading states (components/Skeleton.jsx, the .animate-pulse
// placeholders) stay exactly as they are. When a skeleton is removed from a
// parent and new elements are added to that same parent in the same batch of
// changes (React removes the skeleton and inserts the loaded content in one
// commit), the added elements inside the page content get .fx-reveal once: a
// fade with a short upward slide (see effects.css) instead of popping in.
// Elements that were already on screen are never touched, so a live refresh does
// not replay it. Like every effect here it is off for people who asked for less
// motion (index.js does not install it).
const CONTENT = ".ice-main, .role-shell main";

const isSkeleton = (node) =>
  node.nodeType === 1 && (node.matches(".animate-pulse") || !!node.querySelector(".animate-pulse"));

export function startReveal() {
  const observer = new MutationObserver((records) => {
    // parents that lost a skeleton in this batch
    const swapped = new Set();
    for (const record of records) {
      if ([...record.removedNodes].some(isSkeleton)) swapped.add(record.target);
    }
    if (swapped.size === 0) return;
    for (const record of records) {
      if (!swapped.has(record.target)) continue;
      for (const node of record.addedNodes) {
        if (node.nodeType !== 1 || node.matches(".animate-pulse") || !node.closest(CONTENT)) continue;
        node.classList.add("fx-reveal");
        node.addEventListener("animationend", (e) => {
          if (e.target === node) node.classList.remove("fx-reveal");
        });
      }
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });
}
