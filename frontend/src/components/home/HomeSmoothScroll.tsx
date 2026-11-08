"use client";

import { useEffect } from "react";
import Lenis from "lenis";
import "lenis/dist/lenis.css";

export default function HomeSmoothScroll() {
  useEffect(() => {
    const desktopPointer = window.matchMedia("(hover: hover) and (pointer: fine)");
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
    let scrolling: Lenis | undefined;

    function syncScrollLock(): void {
      // CMS locks body, while Lenis owns the document scroll container.
      if (document.body.classList.contains("menu-scroll-lock")) {
        scrolling?.stop();
      } else {
        scrolling?.start();
      }
    }

    function syncSmoothScroll(): void {
      if (!desktopPointer.matches || reducedMotion.matches) {
        scrolling?.destroy();
        scrolling = undefined;
        return;
      }

      if (!scrolling) {
        scrolling = new Lenis({
          autoRaf: true,
          smoothWheel: true,
          syncTouch: false,
          anchors: true,
          lerp: 0.24,
          prevent: (node) => node.matches("dialog, [role='dialog'], .menu__panel, [data-lenis-prevent]"),
        });
      }
      syncScrollLock();
    }

    const lockObserver = new MutationObserver(syncScrollLock);
    lockObserver.observe(document.body, { attributes: true, attributeFilter: ["class"] });
    desktopPointer.addEventListener("change", syncSmoothScroll);
    reducedMotion.addEventListener("change", syncSmoothScroll);
    syncSmoothScroll();

    return () => {
      lockObserver.disconnect();
      desktopPointer.removeEventListener("change", syncSmoothScroll);
      reducedMotion.removeEventListener("change", syncSmoothScroll);
      scrolling?.destroy();
    };
  }, []);

  return null;
}
