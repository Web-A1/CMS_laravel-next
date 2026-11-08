"use client";

import { useEffect, useRef, type ReactNode } from "react";
import { BrandSurface } from "@/components/content/SurfaceCard";
import { HOME_ENTRANCE_MOTION, HOME_COUNTER_MOTION } from "./homeMotion";

export default function HomeStatsCountUp({ children }: { children: ReactNode }) {
  const sectionRef = useRef<HTMLElement>(null);

  useEffect(() => {
    const section = sectionRef.current;
    const heading = section?.querySelector<HTMLElement>('[data-ui="home.stats.rating.value"]');
    if (!section || !heading) return;

    const captions = Array.from(section.querySelectorAll(".home-stats__card > .ui-card-text"));
    const singleColumn = window.matchMedia("(width < 40rem)");
    let frameId = 0;
    let disposed = false;

    const alignRating = () => {
      if (!singleColumn.matches) {
        section.style.removeProperty("--home-stats-caption-width");
        return;
      }

      const left = heading.getBoundingClientRect().left;
      let right = left;
      const range = document.createRange();

      for (const caption of captions) {
        const walker = document.createTreeWalker(caption, NodeFilter.SHOW_TEXT);
        let node: Node | null;
        while ((node = walker.nextNode())) {
          // Word ranges exclude trailing whitespace at the end of wrapped lines.
          for (const word of (node.textContent ?? "").matchAll(/\S+/gu)) {
            range.setStart(node, word.index);
            range.setEnd(node, word.index + word[0].length);
            for (const rect of range.getClientRects()) right = Math.max(right, rect.right);
          }
        }
      }

      if (right > left) section.style.setProperty("--home-stats-caption-width", `${right - left}px`);
    };

    const scheduleAlignment = () => {
      if (disposed) return;
      window.cancelAnimationFrame(frameId);
      frameId = window.requestAnimationFrame(alignRating);
    };

    const observer = typeof ResizeObserver === "undefined" ? undefined : new ResizeObserver(scheduleAlignment);
    captions.forEach((caption) => observer?.observe(caption));
    singleColumn.addEventListener("change", scheduleAlignment);
    window.addEventListener("resize", scheduleAlignment);
    document.fonts.addEventListener("loadingdone", scheduleAlignment);
    void document.fonts.ready.then(scheduleAlignment);
    scheduleAlignment();

    return () => {
      disposed = true;
      window.cancelAnimationFrame(frameId);
      observer?.disconnect();
      singleColumn.removeEventListener("change", scheduleAlignment);
      window.removeEventListener("resize", scheduleAlignment);
      document.fonts.removeEventListener("loadingdone", scheduleAlignment);
      section.style.removeProperty("--home-stats-caption-width");
    };
  }, []);

  useEffect(() => {
    const section = sectionRef.current;
    if (!section || typeof IntersectionObserver === "undefined") return;

    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
    const counters = Array.from(section.querySelectorAll<HTMLElement>("[data-count-up]")).map((element) => {
      const decimals = Number(element.dataset.countDecimals ?? 0);
      return {
        element,
        target: Number(element.dataset.countUp),
        scale: 10 ** decimals,
        finalText: element.textContent,
        hasStarted: false,
        frameId: 0,
        formatter: new Intl.NumberFormat("ru-RU", {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals,
        }),
      };
    });

    type Counter = (typeof counters)[number];
    let disposed = false;

    function renderProgress(counter: Counter, progress: number): void {
      // Only transient visual text changes; accessible final values stay unchanged.
      counter.element.textContent = progress === 1
        ? counter.finalText
        : counter.formatter.format(Math.floor(counter.target * counter.scale * progress) / counter.scale);
    }

    function finish(counter: Counter): void {
      window.cancelAnimationFrame(counter.frameId);
      counter.frameId = 0;
      renderProgress(counter, 1);
      const card = counter.element.closest<HTMLElement>(".home-stats__card");
      card?.classList.remove("is-home-pending");
      card?.classList.remove("is-home-entering");
      card?.style.removeProperty("--home-entrance-delay");
    }

    const observer = new IntersectionObserver((entries) => {
      if (disposed || document.hidden || reducedMotion.matches) return;

      const visible = entries.filter((entry) => entry.isIntersecting && entry.intersectionRatio >= 0.25)
        .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top || a.boundingClientRect.left - b.boundingClientRect.left);
      for (const [index, entry] of visible.entries()) {
        const counter = counters.find(({ element }) => element === entry.target);
        if (!counter || counter.hasStarted) continue;

        counter.hasStarted = true;
        observer.unobserve(counter.element);
        const card = counter.element.closest<HTMLElement>(".home-stats__card");
        card?.classList.remove("is-home-pending");
        const delay = Math.min(index, 2) * HOME_ENTRANCE_MOTION.staggerMs;
        card?.style.setProperty("--home-entrance-delay", `${delay}ms`);
        if (!card?.contains(document.activeElement)) card?.classList.add("is-home-entering");
        let startedAt: number | undefined;

        const animate = (timestamp: number): void => {
          if (disposed) return;
          startedAt ??= timestamp;
          const progress = Math.max(0, Math.min((timestamp - startedAt - delay) / HOME_COUNTER_MOTION.durationMs, 1));
          renderProgress(counter, 1 - (1 - progress) ** 3);
          counter.frameId = progress < 1 ? window.requestAnimationFrame(animate) : 0;
        };

        counter.frameId = window.requestAnimationFrame(animate);
      }
    }, { threshold: 0.25, rootMargin: HOME_ENTRANCE_MOTION.rootMargin });

    function configure(): void {
      if (disposed) return;
      observer.disconnect();

      for (const counter of counters) {
        if (reducedMotion.matches) {
          counter.hasStarted = true;
          finish(counter);
        } else if (!counter.hasStarted) {
          counter.element.closest(".home-stats__card")?.classList.add("is-home-pending");
          renderProgress(counter, 0);
          observer.observe(counter.element);
        }
      }
    }

    function handleVisibilityChange(): void {
      if (document.hidden) {
        counters.filter(({ hasStarted }) => hasStarted).forEach(finish);
      } else {
        configure();
      }
    }

    function handleFocus(event: FocusEvent): void {
      if (!(event.target instanceof Element)) return;
      const card = event.target.closest(".home-stats__card");
      const counter = counters.find(({ element }) => card?.contains(element));
      if (!counter) return;
      counter.hasStarted = true;
      observer.unobserve(counter.element);
      finish(counter);
    }

    configure();
    section.addEventListener("focusin", handleFocus);
    reducedMotion.addEventListener("change", configure);
    document.addEventListener("visibilitychange", handleVisibilityChange);

    return () => {
      disposed = true;
      observer.disconnect();
      section.removeEventListener("focusin", handleFocus);
      counters.forEach(finish);
      reducedMotion.removeEventListener("change", configure);
      document.removeEventListener("visibilitychange", handleVisibilityChange);
    };
  }, []);

  return (
    <BrandSurface ref={sectionRef} className="home-section home-stats" aria-label="Школа в цифрах" data-ui="home.stats">
      {children}
    </BrandSurface>
  );
}
