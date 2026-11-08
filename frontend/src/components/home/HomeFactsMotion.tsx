"use client";

import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from "react";
import { mountHomeFactsEntrance } from "./homeFactsEntrance";

interface FactsMotion {
  entranceManaged?: boolean;
  puzzleReady: boolean;
  progressReady: boolean;
  skipMotion: boolean;
}

const standaloneMotion: FactsMotion = { puzzleReady: true, progressReady: true, skipMotion: false };
const FactsMotionContext = createContext<FactsMotion>(standaloneMotion);

export function useHomeFactsMotion(): FactsMotion {
  return useContext(FactsMotionContext);
}

/** The section shell preserves the server layout while coordinating its illustrations. */
export default function HomeFactsMotion({ children }: { children: ReactNode }) {
  const sectionRef = useRef<HTMLElement>(null);
  const [ready, setReady] = useState({ puzzleReady: false, progressReady: false, skipMotion: false });

  useEffect(() => {
    const cards = sectionRef.current?.querySelectorAll<HTMLElement>(
      ".home-fact-card--feature, .home-fact-card--lesson",
    );
    if (!cards || typeof ResizeObserver === "undefined") return;

    const observer = new ResizeObserver(entries => {
      for (const { target } of entries) {
        if (!(target instanceof HTMLElement)) continue;
        target.dataset.iconAlign = target.offsetWidth > target.offsetHeight ? "end" : "center";
      }
    });
    cards.forEach(card => observer.observe(card));
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    const section = sectionRef.current;
    if (!section) return;
    const controller = mountHomeFactsEntrance(section, {
      startPuzzle: () => setReady(value => ({ ...value, puzzleReady: true })),
      startProgress: () => setReady(value => ({ ...value, progressReady: true })),
      settle: () => setReady({ puzzleReady: true, progressReady: true, skipMotion: true }),
    });
    return () => controller.dispose();
  }, []);

  return (
    <FactsMotionContext.Provider value={{ ...ready, entranceManaged: true }}>
      <section ref={sectionRef} className="home-section home-facts" data-ui="home.facts" aria-labelledby="home-facts-title">{children}</section>
    </FactsMotionContext.Provider>
  );
}
