"use client";

import { useCallback, useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from "react";

import HorizontalScrollRegion from "@/components/accessibility/HorizontalScrollRegion";
import Button from "@/components/button/Button";
import ButtonArrow from "@/components/button/ButtonArrow";
import { HOME_ENTRANCE_MOTION, HOME_TEAM_MOTION } from "./homeMotion";
import { animateHomeCardCascade } from "./homeCardCascade";

const PAGE_SIZE = 4;
const DESKTOP_QUERY = "(min-width: 64rem)";
const THREAD_SRC = "/images/home/team-thread.svg?v=20261019-fair-loop";
type TeamTransitionPhase = "sliding" | "drawing" | "idle";

type HomeTeamGalleryProps = {
  children: ReactNode;
  total: number;
};

function resetCardMotion(track: HTMLDivElement): void {
  for (const card of track.querySelectorAll<HTMLElement>(".home-teacher-card")) {
    card.style.removeProperty("opacity");
    card.style.removeProperty("translate");
  }
}

function fitTrackToPage(track: HTMLDivElement, page: number, desktop: boolean, keepOutgoing = false): void {
  if (!desktop) {
    track.style.removeProperty("--home-team-track-height");
    return;
  }
  const cards = Array.from(track.querySelectorAll<HTMLElement>(".home-teacher-card"));
  const heights = cards.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE).map((card) => card.getBoundingClientRect().height);
  const height = Math.ceil(Math.max(0, ...heights, keepOutgoing ? track.clientHeight : 0));
  if (height > 0) {
    const value = `${height}px`;
    if (track.style.getPropertyValue("--home-team-track-height") !== value) {
      track.style.setProperty("--home-team-track-height", value);
    }
  }
}

export default function HomeTeamGallery({ children, total }: HomeTeamGalleryProps) {
  const trackId = useId();
  const threadMaskId = useId();
  const trackRef = useRef<HTMLDivElement>(null);
  const threadRef = useRef<SVGSVGElement>(null);
  const threadPathRef = useRef<SVGUseElement>(null);
  const frameRef = useRef(0);
  const galleryRef = useRef<HTMLDivElement>(null);
  const mobileCascadeCleanupRef = useRef<(() => void) | null>(null);
  const pagingAnimationsRef = useRef<Animation[]>([]);
  const pageRef = useRef(0);
  const [page, setPage] = useState(0);
  const [phase, setPhase] = useState<TeamTransitionPhase>("idle");
  const lastPage = Math.max(0, Math.ceil(total / PAGE_SIZE) - 1);

  const animatePage = useCallback((nextPage: number, direction: 1 | -1, entrance = false): void => {
    const track = trackRef.current;
    if (!track) return;

    window.cancelAnimationFrame(frameRef.current);
    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const thread = threadRef.current;
    const path = threadPathRef.current;
    const toScroll = Math.min(nextPage * track.clientWidth, track.scrollWidth - track.clientWidth);
    fitTrackToPage(track, nextPage, window.matchMedia(DESKTOP_QUERY).matches, !reduceMotion && !!thread && !!path && !entrance);
    if (reduceMotion || !thread || !path) {
      pagingAnimationsRef.current.forEach((animation) => animation.cancel());
      pagingAnimationsRef.current = [];
      galleryRef.current?.classList.remove("is-team-pending");
      track.scrollTo({ left: toScroll, behavior: "instant" });
      thread?.style.removeProperty("opacity");
      path?.style.removeProperty("stroke-dashoffset");
      resetCardMotion(track);
      frameRef.current = 0;
      setPhase("idle");
      return;
    }

    {
      const cards = Array.from(track.querySelectorAll<HTMLElement>(".home-teacher-card"));
      const bounds = track.getBoundingClientRect();
      const snapshots = new Map(cards.map((card) => {
        const rect = card.getBoundingClientRect();
        return [card, { left: rect.left, visible: !entrance && rect.right > bounds.left && rect.left < bounds.right, opacity: entrance ? 0 : Number.parseFloat(getComputedStyle(card).opacity) }];
      }));
      pagingAnimationsRef.current.forEach((animation) => animation.cancel());
      resetCardMotion(track);
      const lineOpacity = entrance ? 0 : Number.parseFloat(getComputedStyle(thread).opacity);
      const lineOffset = entrance ? 1 : Number.parseFloat(getComputedStyle(path).strokeDashoffset);
      if (entrance) {
        thread.style.opacity = "0";
        path.style.strokeDashoffset = "1";
      }
      track.scrollTo({ left: toScroll, behavior: "instant" });
      const incoming = cards.slice(nextPage * PAGE_SIZE, (nextPage + 1) * PAGE_SIZE);
      const outgoing = cards.filter((card) => !incoming.includes(card) && snapshots.get(card)?.visible);
      const orderedIncoming = direction === 1 ? incoming : [...incoming].reverse();
      const orderedOutgoing = direction === 1 ? outgoing : [...outgoing].reverse();
      const { durationMs: cardDuration, delayMs: cardDelay, staggerMs: cardStagger,
        opacityFraction: cardOpacityFraction, lineStartMs: drawStartsAt } = HOME_TEAM_MOTION;

      pagingAnimationsRef.current = [
        ...orderedOutgoing.map((card, index) => {
          const snapshot = snapshots.get(card)!;
          const offset = snapshot.left - card.getBoundingClientRect().left;
          return card.animate([
            { opacity: snapshot.opacity, translate: `${offset}px 0` },
            { opacity: 0, translate: `${offset - direction * HOME_TEAM_MOTION.exitDistancePx}px 0` },
          ], { duration: HOME_TEAM_MOTION.exitDurationMs, delay: index * HOME_TEAM_MOTION.exitStaggerMs, easing: HOME_TEAM_MOTION.exitEasing, fill: "both" });
        }),
        ...orderedIncoming.map((card, index) => {
          const snapshot = snapshots.get(card)!;
          const offset = snapshot.visible ? snapshot.left - card.getBoundingClientRect().left : direction * HOME_TEAM_MOTION.distancePx;
          return card.animate([
            { opacity: snapshot.visible ? snapshot.opacity : 0, translate: `${offset}px 0` },
            { opacity: 1, offset: cardOpacityFraction },
            { opacity: 1, translate: "0 0" },
          ], { duration: cardDuration, delay: cardDelay + index * cardStagger, easing: HOME_TEAM_MOTION.easing, fill: "both" });
        }),
      ];
      galleryRef.current?.classList.remove("is-team-pending");

      const startedAt = performance.now();
      let drawing = false;
      setPhase("sliding");
      const animatePaging = (now: number): void => {
        const elapsed = now - startedAt;
        if (elapsed < HOME_TEAM_MOTION.lineFadeOutMs) {
          thread.style.opacity = String(lineOpacity * (1 - elapsed / HOME_TEAM_MOTION.lineFadeOutMs));
          path.style.strokeDashoffset = String(lineOffset);
        } else if (elapsed < drawStartsAt) {
          thread.style.opacity = "0";
          path.style.strokeDashoffset = "1";
        } else {
          if (!drawing) {
            drawing = true;
            setPhase("drawing");
          }
          thread.style.opacity = String(Math.min(1, (elapsed - drawStartsAt) / HOME_TEAM_MOTION.lineFadeInMs));
          path.style.strokeDashoffset = String(1 - Math.min(1, (elapsed - drawStartsAt) / HOME_TEAM_MOTION.lineDrawMs));
        }
        if (elapsed >= Math.max(drawStartsAt + HOME_TEAM_MOTION.lineDrawMs, cardDelay + cardDuration + Math.max(0, incoming.length - 1) * cardStagger)) {
          pagingAnimationsRef.current.forEach((animation) => animation.cancel());
          pagingAnimationsRef.current = [];
          thread.style.removeProperty("opacity");
          path.style.removeProperty("stroke-dashoffset");
          fitTrackToPage(track, nextPage, true);
          frameRef.current = 0;
          setPhase("idle");
        } else {
          frameRef.current = window.requestAnimationFrame(animatePaging);
        }
      };
      frameRef.current = window.requestAnimationFrame(animatePaging);
      return;
    }

  }, []);

  useEffect(() => {
    const track = trackRef.current;
    const gallery = galleryRef.current;
    if (!track || !gallery) return;
    const desktop = window.matchMedia(DESKTOP_QUERY);
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)");
    fitTrackToPage(track, pageRef.current, desktop.matches);
    let entered = false;
    let entranceObserver: IntersectionObserver | undefined;

    function finish(): void {
      entered = true;
      entranceObserver?.disconnect();
      gallery?.classList.remove("is-team-pending");
      window.cancelAnimationFrame(frameRef.current);
      frameRef.current = 0;
      mobileCascadeCleanupRef.current?.();
      mobileCascadeCleanupRef.current = null;
      pagingAnimationsRef.current.forEach((animation) => animation.cancel());
      pagingAnimationsRef.current = [];
      threadRef.current?.style.removeProperty("opacity");
      threadPathRef.current?.style.removeProperty("stroke-dashoffset");
      if (track) resetCardMotion(track);
      if (track) fitTrackToPage(track, pageRef.current, desktop.matches);
      setPhase("idle");
      if (desktop.matches && track) {
        track.scrollTo({ left: pageRef.current * track.clientWidth, behavior: "instant" });
      }
    }

    if (!reduced.matches && !document.hidden && "IntersectionObserver" in window && !gallery.contains(document.activeElement)) {
      gallery.classList.add("is-team-pending");
      entranceObserver = new IntersectionObserver((entries) => {
        if (entered || document.hidden || !entries.some((entry) => entry.isIntersecting)) return;
        entered = true;
        entranceObserver?.disconnect();
        if (desktop.matches) {
          animatePage(pageRef.current, 1, true);
        } else {
          mobileCascadeCleanupRef.current = animateHomeCardCascade(track, ".home-teacher-card", finish);
          gallery.classList.remove("is-team-pending");
        }
      }, { threshold: 0, rootMargin: desktop.matches ? HOME_TEAM_MOTION.rootMargin : HOME_ENTRANCE_MOTION.rootMargin });
      entranceObserver.observe(track);
    }

    let previousWidth: number | undefined;
    const resizeObserver = new ResizeObserver((entries) => {
      const entry = entries.find((item) => item.target === track);
      if (entry) {
        const changed = previousWidth !== undefined && Math.abs(entry.contentRect.width - previousWidth) > 1;
        previousWidth = entry.contentRect.width;
        if (changed) finish();
      }
      fitTrackToPage(track, pageRef.current, desktop.matches, frameRef.current !== 0);
    });
    resizeObserver.observe(track);
    track.querySelectorAll<HTMLElement>(".home-teacher-card").forEach((card) => resizeObserver.observe(card));
    function handleVisibility(): void {
      if (document.hidden) finish();
    }
    function handleScroll(): void {
      // A swipe takes priority over the optional mobile entrance.
      if (!desktop.matches) finish();
    }
    desktop.addEventListener("change", finish);
    reduced.addEventListener("change", finish);
    document.addEventListener("visibilitychange", handleVisibility);
    gallery.addEventListener("focusin", finish);
    track.addEventListener("scroll", handleScroll, { passive: true });
    return () => {
      finish();
      resizeObserver.disconnect();
      track.style.removeProperty("--home-team-track-height");
      desktop.removeEventListener("change", finish);
      reduced.removeEventListener("change", finish);
      document.removeEventListener("visibilitychange", handleVisibility);
      gallery.removeEventListener("focusin", finish);
      track.removeEventListener("scroll", handleScroll);
    };
  }, [animatePage]);

  function goToPage(requestedPage: number): void {
    const nextPage = Math.max(0, Math.min(lastPage, requestedPage));
    if (nextPage === pageRef.current) return;
    const direction = nextPage > pageRef.current ? 1 : -1;
    pageRef.current = nextPage;
    setPage(nextPage);
    animatePage(nextPage, direction);
  }

  function handleKeyDown(event: KeyboardEvent<HTMLDivElement>): void {
    if (event.target !== event.currentTarget || !window.matchMedia(DESKTOP_QUERY).matches) return;

    let targetPage: number;
    switch (event.key) {
      case "ArrowLeft": targetPage = pageRef.current - 1; break;
      case "ArrowRight": targetPage = pageRef.current + 1; break;
      case "Home": targetPage = 0; break;
      case "End": targetPage = lastPage; break;
      default: return;
    }
    event.preventDefault();
    goToPage(targetPage);
  }

  return (
    <div ref={galleryRef} className="home-team__gallery" data-motion-phase={phase} aria-busy={phase !== "idle"}>
      <svg ref={threadRef} className="home-team__thread" viewBox="0 -176 1440 704" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <defs>
          <mask id={threadMaskId} x="0" y="-176" width="1440" height="704" maskUnits="userSpaceOnUse" style={{ maskType: "alpha" }}>
            <image href={THREAD_SRC} x="0" y="-176" width="1440" height="704" preserveAspectRatio="none" />
          </mask>
        </defs>
        <use
          ref={threadPathRef}
          className="home-team__thread-path"
          href={`${THREAD_SRC}#team-thread-centerline`}
          mask={`url(#${threadMaskId})`}
        />
      </svg>
      <div className="absolute right-0 top-0 z-10 hidden gap-2 lg:flex" data-ui="home.team.controls">
        <Button
          type="button"
          variant="secondary"
          iconOnly
          aria-label="Предыдущие преподаватели"
          aria-controls={trackId}
          aria-disabled={page === 0}
          onClick={() => goToPage(pageRef.current - 1)}
          data-ui="home.team.previous"
        >
          <ButtonArrow direction="left" />
        </Button>
        <Button
          type="button"
          variant="secondary"
          iconOnly
          aria-label="Следующие преподаватели"
          aria-controls={trackId}
          aria-disabled={page === lastPage}
          onClick={() => goToPage(pageRef.current + 1)}
          data-ui="home.team.next"
        >
          <ButtonArrow />
        </Button>
        <span className="sr-only" role="status" aria-atomic="true">
          Преподаватели {page * PAGE_SIZE + 1}–{Math.min((page + 1) * PAGE_SIZE, total)} из {total}
        </span>
      </div>
      <HorizontalScrollRegion
        ref={trackRef}
        id={trackId}
        className="home-teacher-track"
        role="region"
        tabIndex={0}
        aria-label="Преподаватели"
        data-ui="home.team.cards"
        onKeyDown={handleKeyDown}
      >
        {children}
      </HorizontalScrollRegion>
    </div>
  );
}
