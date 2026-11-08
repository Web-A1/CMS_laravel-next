"use client";

import { useEffect } from "react";
import { HOME_ENTRANCE_MOTION } from "./homeMotion";
import { animateHomeCardCascade } from "./homeCardCascade";

/** Progressive enhancement: prepare offscreen content before its entrance starts. */
export default function HomeEntranceMotion() {
  useEffect(() => {
    const page = document.querySelector(".home-page");
    if (!page || !("IntersectionObserver" in window)) return;
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)");
    const mobile = window.matchMedia("(width < 48rem)");
    const mobileTargets = new Set(page.querySelectorAll<HTMLElement>(
      ".home-reviews__heading, .home-reviews__viewport, .home-cta-card--lesson",
    ));
    const targets = [...Array.from(page.querySelectorAll<HTMLElement>(
      ".home-promo-video__media, .home-subjects__title, .home-love__intro, .home-love__card, .home-team__intro, .home-learning-comparison__title, .home-comparison, .home-faq__copy, .home-faq__support, .home-faq__item, .home-faq__more",
    )), ...mobileTargets];
    const pending = new Set(targets);
    const cascades = new Map<HTMLElement, () => void>();
    let nextFaqEntranceAt = 0;

    function finish(element: HTMLElement) {
      cascades.get(element)?.();
      cascades.delete(element);
      element.classList.remove("is-home-pending");
      element.classList.remove("is-home-entering");
      if (element.classList.contains("home-faq__item") || element.classList.contains("home-faq__more")) {
        element.style.removeProperty("--home-entrance-delay");
      }
    }

    const observer = new IntersectionObserver((entries) => {
      if (document.hidden || reduced.matches) return;
      const now = performance.now();
      for (const entry of entries) {
        if (!entry.isIntersecting || !pending.has(entry.target as HTMLElement)) continue;
        const element = entry.target as HTMLElement;
        if (mobileTargets.has(element) && !mobile.matches) continue;
        if (!element.getClientRects().length) continue;
        pending.delete(element);
        observer.unobserve(element);
        element.classList.remove("is-home-pending");
        if (!element.contains(document.activeElement)) {
          if (element.classList.contains("home-faq__item") || element.classList.contains("home-faq__more")) {
            const delay = Math.max(0, nextFaqEntranceAt - now);
            element.style.setProperty("--home-entrance-delay", `${delay}ms`);
            nextFaqEntranceAt = now + delay + HOME_ENTRANCE_MOTION.staggerMs;
          }
          element.classList.add("is-home-entering");
          if (element.classList.contains("home-reviews__viewport")) {
            const moreLink = element.closest(".home-reviews")?.querySelector<HTMLElement>(".home-reviews__mobile-link");
            cascades.set(element, animateHomeCardCascade(element, ".review-card", () => finish(element), moreLink ?? undefined));
          }
        }
      }
    }, { threshold: 0, rootMargin: HOME_ENTRANCE_MOTION.rootMargin });

    function configure() {
      observer.disconnect();
      if (!mobile.matches) {
        mobileTargets.forEach((element) => {
          finish(element);
        });
      }
      if (reduced.matches) {
        pending.clear();
        targets.forEach(finish);
      } else if (!document.hidden) {
        pending.forEach((element) => {
          if (mobileTargets.has(element) && !mobile.matches) return;
          element.classList.add("is-home-pending");
          observer.observe(element);
        });
      } else {
        targets.forEach(finish);
      }
    }

    function onFinish(event: Event) {
      if (event instanceof AnimationEvent && !["home-entrance", "home-entrance-offset"].includes(event.animationName)) return;
      if (!(event.target instanceof Element)) return;
      const element = event.target.closest<HTMLElement>(".is-home-entering, .is-home-pending");
      if (element) {
        pending.delete(element);
        observer.unobserve(element);
        finish(element);
      }
    }

    page.addEventListener("animationend", onFinish);
    page.addEventListener("focusin", onFinish);
    reduced.addEventListener("change", configure);
    mobile.addEventListener("change", configure);
    document.addEventListener("visibilitychange", configure);
    configure();
    return () => {
      observer.disconnect();
      targets.forEach(finish);
      page.removeEventListener("animationend", onFinish);
      page.removeEventListener("focusin", onFinish);
      reduced.removeEventListener("change", configure);
      mobile.removeEventListener("change", configure);
      document.removeEventListener("visibilitychange", configure);
    };
  }, []);

  return null;
}
