import { HOME_HERO_MOTION, HOME_TEAM_MOTION } from "./homeMotion";

// Incoming directions traced from the owner's annotated composition.
const CARD_FLIGHT_DIRECTIONS: Readonly<Record<string, { x: number; y: number }>> = {
  "1": { x: -0.75, y: 1 },
  "2": { x: -0.75, y: 1 },
  "3": { x: -1, y: 1 },
  "4": { x: -0.75, y: 1 },
  "5": { x: 0.28, y: 1 },
  "6": { x: 0.8, y: 1 },
  "7": { x: 0, y: 1 },
  "8": { x: 0.95, y: 1 },
  "9": { x: 0.68, y: 1 },
};

/** One desktop flight per mount; pending decor is revealed only after WAAPI is ready. */
export function animateHomeHero(hero: HTMLElement): () => void {
  const nonMobile = window.matchMedia("(width > 480px)");
  const isNonMobile = nonMobile.matches;
  const reduced = window.matchMedia("(prefers-reduced-motion: reduce)");
  const scene = hero.querySelector<HTMLElement>(".home-hero__non-mobile-scene");
  const editing = scene?.dataset.editing === "true" ||
    (process.env.NODE_ENV === "development" && new URLSearchParams(window.location.search).get("hero-editor") === "1");
  if (!isNonMobile || reduced.matches || document.hidden || !scene ||
      editing || hero.contains(document.activeElement)) {
    hero.dataset.heroMotion = "static";
    return () => {};
  }

  const animations: Animation[] = [];
  const floating: Animation[] = [];
  let floatObserver: IntersectionObserver | undefined;
  let finished = false;
  const visible = (element: HTMLElement) => element.getClientRects().length > 0;
  const viewportWidth = document.documentElement.clientWidth;
  const viewportHeight = window.innerHeight;
  // Finish all layout reads before any animation changes the measured geometry.
  const cards = [...scene.querySelectorAll<HTMLElement>("[data-card]")]
    .filter(visible)
    .map(element => {
      const rect = element.getBoundingClientRect();
      const centerX = rect.left + rect.width / 2;
      const centerY = rect.top + rect.height / 2;
      const distances = [centerX, viewportWidth - centerX, centerY, viewportHeight - centerY];
      const edge = distances.indexOf(Math.min(...distances));
      const direction = CARD_FLIGHT_DIRECTIONS[element.dataset.card ?? ""] ??
        [{ x: 1, y: 0 }, { x: -1, y: 0 }, { x: 0, y: 1 }, { x: 0, y: -1 }][edge];
      return {
        element, rect, centerX, centerY, edge, direction,
        picture: element.querySelector(":scope > picture"),
        shadow: element.querySelector(".home-hero__float-shadow"),
      };
    });
  if (!cards.length) {
    hero.dataset.heroMotion = "static";
    return () => {};
  }
  // Populate every side before adding the next card on the same side.
  // Within a side, fill from its middle towards the corners.
  const groups = [2, 0, 1, 3].map(edge => cards.filter(card => card.edge === edge).sort((a, b) =>
    edge < 2
      ? Math.abs(a.centerY - viewportHeight / 2) - Math.abs(b.centerY - viewportHeight / 2)
      : Math.abs(a.centerX - viewportWidth / 2) - Math.abs(b.centerX - viewportWidth / 2),
  ));
  const cascade: typeof cards = [];
  for (let round = 0; round < Math.max(0, ...groups.map(group => group.length)); round++) {
    for (const group of groups) {
      if (group[round]) cascade.push(group[round]);
    }
  }
  const startTime = document.timeline.currentTime;

  function play(element: Element, frames: Keyframe[], timing: KeyframeAnimationOptions): void {
    const animation = element.animate(frames, timing);
    // A common clock keeps card and shadow delays in phase.
    if (startTime !== null) animation.startTime = startTime;
    animations.push(animation);
    if (timing.iterations === Infinity) floating.push(animation);
  }

  cascade.forEach(({ rect, picture, shadow, centerX, centerY, direction }, index) => {
    if (!picture) return;
    const halfWidth = rect.width * HOME_HERO_MOTION.startScale / 2;
    const halfHeight = rect.height * HOME_HERO_MOTION.startScale / 2;
    // Extend the arrow backwards until the enlarged card is fully offscreen.
    // A single distance preserves its angle across responsive layouts.
    const inset = HOME_HERO_MOTION.edgeInsetPx;
    const horizontalTravel = direction.x > 0
      ? (centerX + halfWidth + inset) / direction.x
      : direction.x < 0 ? (viewportWidth - centerX + halfWidth + inset) / -direction.x : Infinity;
    const verticalTravel = direction.y > 0
      ? (centerY + halfHeight + inset) / direction.y
      : direction.y < 0 ? (viewportHeight - centerY + halfHeight + inset) / -direction.y : Infinity;
    const travel = Math.max(0, Math.min(horizontalTravel, verticalTravel));
    const x = -direction.x * travel;
    const y = -direction.y * travel;
    const flightMs = HOME_HERO_MOTION.flightMs;
    const staggerMs = HOME_HERO_MOTION.cardStaggerMs;
    const delay = index * staggerMs;
    play(picture, [
      { transform: `translate(${x}px, ${y}px) scale(${HOME_HERO_MOTION.startScale})` },
      { transform: "none" },
    ], { duration: flightMs, delay, easing: HOME_TEAM_MOTION.easing, fill: "backwards" });
    if (shadow) {
      // Grow at the final ground position throughout the card's flight.
      play(shadow, [
        { transform: "scale(0)" },
        { transform: "scale(1)" },
      ], {
        duration: HOME_HERO_MOTION.flightMs,
        delay,
        easing: "linear",
        fill: "backwards",
      });
      play(shadow, [
        { opacity: 0 },
        { opacity: HOME_HERO_MOTION.shadowOpacity },
      ], {
        duration: HOME_HERO_MOTION.flightMs,
        delay,
        easing: HOME_HERO_MOTION.easing,
        fill: "backwards",
      });
    }
    const floatDistance = HOME_HERO_MOTION.floatDistancePx;
    const floatTiming: KeyframeAnimationOptions = {
      duration: HOME_HERO_MOTION.floatDurationMs + index * HOME_HERO_MOTION.floatStaggerMs,
      delay: delay + flightMs,
      iterations: Infinity,
      easing: "ease-in-out",
    };
    play(picture, [
      { transform: "translateY(0px)", offset: 0 },
      { transform: `translateY(${index % 2 ? floatDistance : -floatDistance}px)`, offset: 0.5 },
      { transform: "translateY(0px)", offset: 1 },
    ], floatTiming);
    if (shadow) {
      // The ground stays still; a lower card casts a larger, denser shadow.
      play(shadow, [
        { transform: "scale(1)", opacity: HOME_HERO_MOTION.shadowOpacity, offset: 0 },
        { transform: `scale(${index % 2 ? 1.06 : 0.94})`, opacity: index % 2 ? HOME_HERO_MOTION.shadowLowerOpacity : HOME_HERO_MOTION.shadowHigherOpacity, offset: 0.5 },
        { transform: "scale(1)", opacity: HOME_HERO_MOTION.shadowOpacity, offset: 1 },
      ], floatTiming);
    }
  });

  hero.dataset.heroMotion = "playing";

  function finish(): void {
    hero.dataset.heroMotion = "static";
    dispose();
  }
  function dispose(): void {
    finished = true;
    floatObserver?.disconnect();
    animations.forEach(animation => animation.cancel());
    window.removeEventListener("resize", finish);
    reduced.removeEventListener("change", finish);
    nonMobile.removeEventListener("change", finish);
    document.removeEventListener("visibilitychange", onVisibility);
    hero.removeEventListener("focusin", finish);
  }
  function onVisibility(): void {
    if (document.hidden) finish();
  }
  if (typeof IntersectionObserver !== "undefined") {
    floatObserver = new IntersectionObserver(([entry]) => {
      if (finished || !entry) return;
      for (const animation of floating) {
        if (entry.isIntersecting) {
          if (animation.playState === "paused") animation.play();
        } else {
          animation.pause();
        }
      }
    }, { threshold: 0 });
    floatObserver.observe(hero);
  }
  window.addEventListener("resize", finish);
  reduced.addEventListener("change", finish);
  nonMobile.addEventListener("change", finish);
  document.addEventListener("visibilitychange", onVisibility);
  hero.addEventListener("focusin", finish);
  // Unmount discards the DOM; leaving the prepared marker also avoids replaying
  // the CSS copy fade during React's development effect cleanup/setup check.
  return dispose;
}
