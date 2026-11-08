"use client";

import { useEffect, useRef, type ReactNode } from "react";
import { HOME_ENTRANCE_MOTION } from "./homeMotion";

type HomeSubjectsRevealProps = {
  readonly children: ReactNode;
};

export default function HomeSubjectsReveal({ children }: HomeSubjectsRevealProps) {
  const gridRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const grid = gridRef.current;
    if (!grid || !("IntersectionObserver" in window)) return;

    const cards = Array.from(grid.querySelectorAll<HTMLElement>(":scope > .home-subject-card"));
    if (cards.length === 0) return;

    const pending = new Set(cards);
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
    let disposed = false;

    const observer = new IntersectionObserver((entries) => {
      if (disposed || document.hidden || reducedMotion.matches) return;

      const visible = entries
        .filter((entry) => entry.isIntersecting && entry.intersectionRatio >= 0.12 && pending.has(entry.target as HTMLElement))
        .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top || a.boundingClientRect.left - b.boundingClientRect.left);

      visible.forEach((entry, index) => {
        const card = entry.target as HTMLElement;
        pending.delete(card);
        observer.unobserve(card);
        card.style.setProperty("--home-subject-reveal-delay", `${Math.min(index, 2) * HOME_ENTRANCE_MOTION.staggerMs}ms`);
        card.classList.remove("is-reveal-pending");
        card.classList.add("is-revealing");
      });
    }, { threshold: 0.12, rootMargin: HOME_ENTRANCE_MOTION.rootMargin });

    function finish(card: HTMLElement) {
      pending.delete(card);
      observer.unobserve(card);
      card.classList.remove("is-reveal-pending", "is-revealing");
      card.style.removeProperty("--home-subject-reveal-delay");
    }

    function configure() {
      observer.disconnect();
      if (disposed) return;
      if (reducedMotion.matches) {
        cards.forEach(finish);
        return;
      }
      if (document.hidden) return;
      pending.forEach((card) => {
        if (card.contains(document.activeElement)) {
          finish(card);
        } else {
          card.classList.add("is-reveal-pending");
          observer.observe(card);
        }
      });
    }

    function onFocus(event: FocusEvent) {
      if (!(event.target instanceof Element)) return;
      const card = event.target.closest<HTMLElement>(".home-subject-card");
      if (card && grid?.contains(card)) finish(card);
    }

    function onAnimationEnd(event: AnimationEvent) {
      if (event.animationName !== "home-subject-reveal" || !(event.target instanceof HTMLElement)) return;
      if (event.target.classList.contains("is-revealing")) finish(event.target);
    }

    function onVisibilityChange() {
      if (document.hidden) {
        observer.disconnect();
        cards.filter((card) => card.classList.contains("is-revealing")).forEach(finish);
      } else {
        configure();
      }
    }

    grid.addEventListener("focusin", onFocus);
    grid.addEventListener("animationend", onAnimationEnd);
    document.addEventListener("visibilitychange", onVisibilityChange);
    reducedMotion.addEventListener("change", configure);
    configure();

    return () => {
      disposed = true;
      observer.disconnect();
      cards.forEach(finish);
      grid.removeEventListener("focusin", onFocus);
      grid.removeEventListener("animationend", onAnimationEnd);
      document.removeEventListener("visibilitychange", onVisibilityChange);
      reducedMotion.removeEventListener("change", configure);
    };
  }, []);

  return (
    <div ref={gridRef} className="subject-grid home-subjects__grid" data-ui="home.subjects.cards">
      {children}
    </div>
  );
}
