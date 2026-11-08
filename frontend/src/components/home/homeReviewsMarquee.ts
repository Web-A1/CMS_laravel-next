import { HOME_MARQUEE_MOTION } from "./homeMotion";

/** Keeps motion on small card layers; JS only handles visibility and layout changes. */
export function mountHomeReviewsMarquee(viewport: HTMLElement, track: HTMLElement): () => void {
  if (!("IntersectionObserver" in window) || !("ResizeObserver" in window)) return () => {};

  const motion = window.matchMedia("(min-width: 48rem) and (prefers-reduced-motion: no-preference)");
  const cards = Array.from(track.querySelectorAll<HTMLElement>(".review-card"));
  let isIntersecting = false;
  let geometry = "";

  function clearGeometry(): void {
    geometry = "";
    viewport.removeAttribute("data-marquee-ready");
    viewport.removeAttribute("data-marquee-active");
    viewport.style.removeProperty("--home-reviews-loop-from");
    viewport.style.removeProperty("--home-reviews-loop-to");
    viewport.style.removeProperty("--home-reviews-duration");
    for (const card of cards) card.style.removeProperty("--home-reviews-card-delay");
  }

  function syncPlayback(): void {
    if (!geometry) return;
    const active = isIntersecting && document.visibilityState === "visible";
    viewport.setAttribute("data-marquee-active", String(active));
  }

  function measure(): void {
    if (!motion.matches) {
      if (geometry) clearGeometry();
      return;
    }

    // Read geometry together, never from a scroll handler or animation frame.
    const height = viewport.clientHeight;
    const gap = Number.parseFloat(window.getComputedStyle(track).rowGap) || 0;
    const heights = cards.map(card => card.getBoundingClientRect().height);
    const cycle = heights.reduce((sum, cardHeight) => sum + cardHeight + gap, 0);

    // Each card must be completely outside the viewport at its wrap point.
    if (!height || cycle <= height + Math.max(0, ...heights) + gap) {
      if (geometry) clearGeometry();
      return;
    }

    const nextGeometry = [height, gap, ...heights].join(",");
    if (nextGeometry === geometry) return;
    geometry = nextGeometry;
    const durationMs = cycle / HOME_MARQUEE_MOTION.speedPxPerSecond * 1000;

    let offset = 0;
    for (const [index, card] of cards.entries()) {
      const phase = ((height - offset + cycle) % cycle) / cycle;
      card.style.setProperty("--home-reviews-card-delay", `${-phase * durationMs}ms`);
      offset += heights[index] + gap;
    }
    viewport.style.setProperty("--home-reviews-loop-from", `${height}px`);
    viewport.style.setProperty("--home-reviews-loop-to", `${height - cycle}px`);
    viewport.style.setProperty("--home-reviews-duration", `${durationMs}ms`);
    viewport.setAttribute("data-marquee-ready", "true");
    syncPlayback();
  }

  const intersectionObserver = new window.IntersectionObserver(([entry]) => {
    isIntersecting = entry?.isIntersecting ?? false;
    syncPlayback();
  });
  const resizeObserver = new window.ResizeObserver(measure);
  intersectionObserver.observe(viewport);
  resizeObserver.observe(viewport);
  for (const card of cards) resizeObserver.observe(card);
  motion.addEventListener("change", measure);
  document.addEventListener("visibilitychange", syncPlayback);
  measure();

  return () => {
    intersectionObserver.disconnect();
    resizeObserver.disconnect();
    motion.removeEventListener("change", measure);
    document.removeEventListener("visibilitychange", syncPlayback);
    clearGeometry();
  };
}
