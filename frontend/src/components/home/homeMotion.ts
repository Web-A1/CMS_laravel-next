const ENTRANCE_DURATION_MS = 900;

// The existing overdamped entrance spring: mass 1.2, damping 60, stiffness 400.
function homeMotionProgress(fraction: number): number {
  if (fraction <= 0) return 0;
  if (fraction >= 1) return 1;
  const slow = (-60 + Math.sqrt(60 ** 2 - 4 * 1.2 * 400)) / (2 * 1.2);
  const fast = (-60 - Math.sqrt(60 ** 2 - 4 * 1.2 * 400)) / (2 * 1.2);
  const seconds = fraction * ENTRANCE_DURATION_MS / 1000;
  return 1 - (fast * Math.exp(slow * seconds) - slow * Math.exp(fast * seconds)) / (fast - slow);
}

const entranceEasing = `linear(${Array.from({ length: 55 }, (_, index) =>
  Number(homeMotionProgress(index / 54).toFixed(5)),
).join(", ")})`;

/** Shared one-shot entrances; CSS receives the same values through SSR. */
export const HOME_ENTRANCE_MOTION = {
  durationMs: ENTRANCE_DURATION_MS,
  staggerMs: 80,
  horizontalDistancePx: 100,
  rootMargin: "0px 0px -80px 0px",
  easing: entranceEasing,
} as const;

/** Owner-approved desktop entrance and paging composition. */
export const HOME_TEAM_MOTION = {
  durationMs: 1300,
  delayMs: 100,
  staggerMs: 140,
  distancePx: 56,
  opacityFraction: 0.5,
  easing: "cubic-bezier(0.25, 0.1, 0.25, 1)",
  exitDurationMs: 420,
  exitStaggerMs: 35,
  exitDistancePx: 40,
  exitEasing: "ease-in-out",
  lineFadeOutMs: 220,
  lineStartMs: 450,
  lineFadeInMs: 300,
  lineDrawMs: 1100,
  rootMargin: "0px 0px -160px 0px",
} as const;

/** Static phone hero; fast first-paint desktop copy and the original card flight. */
export const HOME_HERO_MOTION = {
  copyFadeMs: 200,
  flightMs: HOME_TEAM_MOTION.durationMs,
  cardStaggerMs: 100,
  startScale: 2,
  edgeInsetPx: 16,
  easing: "cubic-bezier(0.4, 0, 0.2, 1)",
  floatDistancePx: 14,
  floatDurationMs: 7200,
  floatStaggerMs: 160,
  shadowOpacity: 0.06,
  shadowLowerOpacity: 0.0675,
  shadowHigherOpacity: 0.045,
  promoFadeMs: 900,
} as const;

export const HOME_COUNTER_MOTION = { durationMs: 1800 } as const;
export const HOME_MARQUEE_MOTION = { speedPxPerSecond: 50 } as const;

/** Full textbook to notes, followed by a quicker return to manual comparison. */
export const HOME_COMPARISON_MOTION = {
  sweepMs: 2400,
  returnMs: 850,
  progress: (fraction: number): number => fraction * fraction * (3 - 2 * fraction),
} as const;
