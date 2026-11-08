import { HOME_ENTRANCE_MOTION } from "./homeMotion";

/** Reveal visible mobile cards from the right; keep lazy replacements on the same timeline. */
export function animateHomeCardCascade(root: HTMLElement, selector: string, onComplete: () => void, companion?: HTMLElement): () => void {
  const startedAt = performance.now();
  const animations = new Map<HTMLElement, Animation>();
  let timer: ReturnType<typeof setTimeout>;
  let endsAt = startedAt;
  let companionAnimation: Animation | undefined;
  const easing = getComputedStyle(root).getPropertyValue("--home-entrance-easing").trim();

  function synchronize(): void {
    const bounds = root.getBoundingClientRect();
    const cards = Array.from(root.querySelectorAll<HTMLElement>(selector))
      .filter((card) => {
        const rect = card.getBoundingClientRect();
        return rect.width > 0 && rect.right > Math.max(0, bounds.left) && rect.left < Math.min(window.innerWidth, bounds.right);
      })
      .sort((a, b) => b.getBoundingClientRect().left - a.getBoundingClientRect().left);
    const elapsed = performance.now() - startedAt;
    const duration = HOME_ENTRANCE_MOTION.durationMs + Math.max(0, cards.length - 1) * HOME_ENTRANCE_MOTION.staggerMs;
    endsAt = Math.max(endsAt, startedAt + duration);
    for (const [card, animation] of animations) {
      if (!root.contains(card) || !card.getClientRects().length) {
        animation.cancel();
        animations.delete(card);
      }
    }
    cards.forEach((card, index) => {
      if (animations.has(card) || elapsed >= duration) return;
      const animation = card.animate([
        { opacity: 0, translate: `${HOME_ENTRANCE_MOTION.horizontalDistancePx}px 0` },
        { opacity: 1, translate: "0 0" },
      ], { duration: HOME_ENTRANCE_MOTION.durationMs, delay: index * HOME_ENTRANCE_MOTION.staggerMs, easing, fill: "both" });
      animation.currentTime = elapsed;
      animations.set(card, animation);
    });
    if (companion && !companionAnimation && cards.length > 0) {
      companionAnimation = companion.animate([
        { opacity: 0, translate: `${HOME_ENTRANCE_MOTION.horizontalDistancePx}px 0` },
        { opacity: 1, translate: "0 0" },
      ], { duration: HOME_ENTRANCE_MOTION.durationMs, delay: Math.max(0, cards.length - 2) * HOME_ENTRANCE_MOTION.staggerMs, easing, fill: "both" });
      companionAnimation.currentTime = elapsed;
    }
    clearTimeout(timer);
    timer = setTimeout(onComplete, Math.max(0, endsAt - performance.now()));
  }

  const observer = new MutationObserver(synchronize);
  root.classList.add("is-home-cascade");
  observer.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ["hidden"] });
  root.addEventListener("scroll", onComplete, true);
  companion?.addEventListener("focusin", onComplete);
  synchronize();
  return () => {
    observer.disconnect();
    root.classList.remove("is-home-cascade");
    root.removeEventListener("scroll", onComplete, true);
    companion?.removeEventListener("focusin", onComplete);
    clearTimeout(timer);
    animations.forEach((animation) => animation.cancel());
    animations.clear();
    companionAnimation?.cancel();
  };
}
