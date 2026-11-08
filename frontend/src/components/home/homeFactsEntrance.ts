import { HOME_ENTRANCE_MOTION } from "./homeMotion";

interface FactsEntranceCallbacks {
  startPuzzle: () => void;
  startProgress: () => void;
  settle: () => void;
}

/** Equal homepage cascade intervals; illustrations overlap the quiet end of travel. */
export function mountHomeFactsEntrance(section: HTMLElement, callbacks: FactsEntranceCallbacks) {
  const title = section.querySelector<HTMLElement>("#home-facts-title");
  const license = section.querySelector<HTMLElement>(".home-fact-card--license");
  const feature = section.querySelector<HTMLElement>(".home-fact-card--feature");
  const lesson = section.querySelector<HTMLElement>(".home-fact-card--lesson");
  if (!title || !license || !feature || !lesson || typeof IntersectionObserver === "undefined" || typeof title.animate !== "function") {
    callbacks.settle();
    return { dispose() {} };
  }

  const stages = [title, license, feature, lesson];
  const targets = stages;
  const pending = new Set(targets);
  const visible = new Set<Element>();
  const animations = new Map<HTMLElement, Animation>();
  const frames = new Map<HTMLElement, number>();
  const reduced = window.matchMedia("(prefers-reduced-motion: reduce)");
  const phone = window.matchMedia("(max-width: 480px)");
  const easing = getComputedStyle(section).getPropertyValue("--home-entrance-easing").trim() || HOME_ENTRANCE_MOTION.easing;
  let disposed = false;
  let hasStarted = false;

  function show(element: HTMLElement): void {
    const frame = frames.get(element);
    if (frame !== undefined) window.cancelAnimationFrame(frame);
    frames.delete(element);
    element.style.removeProperty("opacity");
    animations.get(element)?.cancel();
    animations.delete(element);
  }

  function reveal(element: HTMLElement, delay: number, onComplete?: () => void): void {
    pending.delete(element);
    visible.delete(element);
    observer.unobserve(element);
    hasStarted = true;
    const distance = element === title || (element === license && !phone.matches)
      ? -HOME_ENTRANCE_MOTION.horizontalDistancePx : HOME_ENTRANCE_MOTION.horizontalDistancePx;
    const animation = element.animate([
      { opacity: 0, translate: `${distance}px 0` },
      { opacity: 1, translate: "0 0" },
    ], { duration: HOME_ENTRANCE_MOTION.durationMs, delay, easing, fill: "both" });
    animations.set(element, animation);
    let illustrationStarted = false;
    function startIllustration(): void {
      if (disposed || illustrationStarted) return;
      illustrationStarted = true;
      onComplete?.();
    }
    function watchPosition(): void {
      frames.delete(element);
      if (disposed || !animations.has(element)) return;
      const progress = animation.effect?.getComputedTiming().progress;
      // Eased progress follows the actual spring, whose quiet tail lasts until 900ms.
      if (progress != null && (1 - progress) * Math.abs(distance) <= 4) {
        startIllustration();
      } else {
        frames.set(element, window.requestAnimationFrame(watchPosition));
      }
    }
    if (onComplete) frames.set(element, window.requestAnimationFrame(watchPosition));
    animation.onfinish = () => {
      if (disposed) return;
      show(element);
      startIllustration();
    };
  }

  function advance(): void {
    if (disposed || document.hidden || reduced.matches) return;
    // Only visible cards join the cascade; offscreen siblings wait for their own entry.
    stages.filter(stage => pending.has(stage) && visible.has(stage)).forEach((stage, index) => {
      const onComplete = stage === feature ? callbacks.startPuzzle : stage === lesson ? callbacks.startProgress : undefined;
      reveal(stage, index * HOME_ENTRANCE_MOTION.staggerMs, onComplete);
    });
  }

  const observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting && (entry.target === title || !phone.matches || entry.intersectionRatio >= 0.15)) visible.add(entry.target);
      else visible.delete(entry.target);
    }
    advance();
  }, { threshold: [0, 0.15], rootMargin: "0px" });

  function settle(): void {
    observer.disconnect();
    targets.forEach(show);
    pending.clear();
    callbacks.settle();
  }

  function configure(): void {
    if (reduced.matches || (document.hidden && hasStarted)) {
      settle();
    } else if (!document.hidden) {
      observer.disconnect();
      visible.clear();
      pending.forEach(target => observer.observe(target));
    }
  }

  function handleFocus(): void { settle(); }

  targets.forEach(target => { target.style.opacity = "0"; });
  section.addEventListener("focusin", handleFocus);
  reduced.addEventListener("change", configure);
  document.addEventListener("visibilitychange", configure);
  configure();
  return {
    dispose(): void {
      disposed = true;
      observer.disconnect();
      targets.forEach(show);
      section.removeEventListener("focusin", handleFocus);
      reduced.removeEventListener("change", configure);
      document.removeEventListener("visibilitychange", configure);
    },
  };
}
