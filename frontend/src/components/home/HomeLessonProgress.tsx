"use client";

import { useEffect, useId, useRef } from "react";
import { HOME_COUNTER_MOTION, HOME_ENTRANCE_MOTION } from "./homeMotion";
import { useHomeFactsMotion } from "./HomeFactsMotion";

export default function HomeLessonProgress() {
  const { progressReady, skipMotion, entranceManaged } = useHomeFactsMotion();
  const id = useId();
  const svgRef = useRef<SVGSVGElement>(null);
  const revealRef = useRef<SVGCircleElement>(null);
  const knobRef = useRef<SVGGElement>(null);
  const labelRef = useRef<SVGTextElement>(null);

  useEffect(() => {
    const svg = svgRef.current;
    const reveal = revealRef.current;
    const knob = knobRef.current;
    const label = labelRef.current;
    if (!svg || !reveal || !knob || !label || typeof IntersectionObserver === "undefined") return;

    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)");
    if (reduced.matches || skipMotion) return;

    let frame = 0;
    let started = false;
    let disposed = false;
    let startedAt: number | undefined;
    let lastValue = 100;

    function render(progress: number): void {
      reveal?.setAttribute("stroke-dashoffset", String(100 * (1 - progress)));
      knob?.setAttribute("transform", `rotate(${360 * progress} 50 50)`);
      const value = Math.floor(progress * 100);
      if (label && value !== lastValue) {
        label.textContent = `${value}%`;
        lastValue = value;
      }
    }

    if (entranceManaged && !progressReady) {
      render(0);
      return () => render(1);
    }

    function finish(): void {
      started = true;
      window.cancelAnimationFrame(frame);
      frame = 0;
      observer.disconnect();
      render(1);
    }

    function animate(timestamp: number): void {
      if (disposed) return;
      startedAt ??= timestamp;
      const fraction = Math.min(Math.max((timestamp - startedAt) / HOME_COUNTER_MOTION.durationMs, 0), 1);
      render(1 - (1 - fraction) ** 3);
      if (fraction < 1) frame = window.requestAnimationFrame(animate);
      else finish();
    }

    function start(): void {
      if (disposed || started || document.hidden || !progressReady) return;
      started = true;
      frame = window.requestAnimationFrame(animate);
    }

    const observer = new IntersectionObserver((entries) => {
      if (disposed) return;
      if (entries.some((entry) => entry.isIntersecting && entry.intersectionRatio >= 0.25)) start();
      else if (started && entries.some((entry) => !entry.isIntersecting)) finish();
    }, { threshold: 0.25, rootMargin: HOME_ENTRANCE_MOTION.rootMargin });

    const target = svg.closest("article") ?? svg;
    function handleMotionChange(): void {
      if (reduced.matches) finish();
    }
    function handleVisibilityChange(): void {
      if (document.hidden && started) finish();
      else if (!document.hidden && !started) {
        observer.disconnect();
        observer.observe(target);
      }
    }

    // SSR/no-JS keeps the completed graphic; only enhance it once mounted.
    render(0);
    observer.observe(target);
    if (entranceManaged && progressReady) {
      const rect = target.getBoundingClientRect();
      if (Math.min(rect.bottom, window.innerHeight) - Math.max(rect.top, 0) >= rect.height * 0.25) start();
      else finish();
    }
    reduced.addEventListener("change", handleMotionChange);
    document.addEventListener("visibilitychange", handleVisibilityChange);
    return () => {
      disposed = true;
      finish();
      reduced.removeEventListener("change", handleMotionChange);
      document.removeEventListener("visibilitychange", handleVisibilityChange);
    };
  }, [progressReady, skipMotion, entranceManaged]);

  return (
    <svg ref={svgRef} className="block size-full" viewBox="0 0 100 100" fill="none" aria-hidden="true" focusable="false">
      <defs>
        <linearGradient id={`${id}-start`} x1="50" y1="9" x2="50" y2="91" gradientUnits="userSpaceOnUse">
          <stop stopColor="currentColor" stopOpacity="0.25" />
          <stop offset="1" stopColor="currentColor" stopOpacity="0.65" />
        </linearGradient>
        <linearGradient id={`${id}-end`} x1="50" y1="91" x2="50" y2="9" gradientUnits="userSpaceOnUse">
          <stop stopColor="currentColor" stopOpacity="0.65" />
          <stop offset="1" stopColor="currentColor" />
        </linearGradient>
        <mask id={`${id}-reveal`} maskUnits="userSpaceOnUse" x="0" y="0" width="100" height="100">
          <circle ref={revealRef} cx="50" cy="50" r="41" pathLength="100" stroke="white" strokeWidth="10" strokeDasharray="100" strokeDashoffset="0" transform="rotate(-90 50 50)" />
        </mask>
      </defs>
      <g mask={`url(#${id}-reveal)`}>
        <path d="M50 9a41 41 0 0 1 0 82" stroke={`url(#${id}-start)`} strokeWidth="8" />
        <path d="M50 91a41 41 0 0 1 0-82" stroke={`url(#${id}-end)`} strokeWidth="8" />
      </g>
      <g ref={knobRef}>
        <circle cx="50" cy="9" r="7" fill="currentColor" stroke="white" strokeWidth="3" />
      </g>
      <text ref={labelRef} x="50" y="50" textAnchor="middle" dominantBaseline="central" fill="currentColor" fontSize="20" className="font-bold tabular-nums">100%</text>
    </svg>
  );
}
