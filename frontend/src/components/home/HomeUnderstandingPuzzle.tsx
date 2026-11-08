"use client";

import { useEffect, useRef } from "react";
import { HOME_COUNTER_MOTION, HOME_ENTRANCE_MOTION } from "./homeMotion";
import { useHomeFactsMotion } from "./HomeFactsMotion";

const finalPiece = "M60 20H94Q100 20 100 26V60H87C86 60 85 58 85 56C85 49 75 49 75 56C75 58 74 60 73 60H60V47C60 46 58 45 56 45C49 45 49 35 56 35C58 35 60 34 60 33Z";
const startTransform = "translate(24px, -26px) rotate(-16deg) scale(1.06)";

export default function HomeUnderstandingPuzzle() {
  const { puzzleReady, skipMotion, entranceManaged } = useHomeFactsMotion();
  const svgRef = useRef<SVGSVGElement>(null);
  const pieceRef = useRef<SVGGElement>(null);

  useEffect(() => {
    const svg = svgRef.current;
    const piece = pieceRef.current;
    if (!svg || !piece || typeof IntersectionObserver === "undefined" || typeof piece.animate !== "function") {
      return;
    }
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)");
    if (reduced.matches || skipMotion) {
      return;
    }

    if (entranceManaged && !puzzleReady) {
      piece.style.transform = startTransform;
      piece.style.opacity = "0";
      return () => {
        piece.style.removeProperty("transform");
        piece.style.removeProperty("opacity");
      };
    }

    let started = false;
    let disposed = false;
    let animation: Animation | undefined;
    const target = svg.closest("article") ?? svg;

    function finish(): void {
      started = true;
      observer.disconnect();
      animation?.cancel();
      animation = undefined;
      piece?.style.removeProperty("transform");
      piece?.style.removeProperty("opacity");
    }

    function start(): void {
      if (!piece || disposed || started || document.hidden || !puzzleReady) return;
      started = true;
      animation = piece.animate([
        { transform: startTransform, opacity: 0.35, offset: 0 },
        { transform: "translate(20px, -22px) rotate(-12deg) scale(1.04)", opacity: 1, offset: 0.08 },
        { transform: "translate(1px, -1px) rotate(-1deg) scale(1)", opacity: 1, offset: 0.78 },
        { transform: "translate(0, 0) rotate(0deg) scale(1)", opacity: 1, offset: 1 },
      ], { duration: HOME_COUNTER_MOTION.durationMs, easing: "cubic-bezier(0.2, 0, 0.2, 1)", fill: "forwards" });
      animation.onfinish = finish;
    }

    const observer = new IntersectionObserver((entries) => {
      if (disposed) return;
      if (entries.some((entry) => entry.isIntersecting && entry.intersectionRatio >= 0.25)) start();
      else if (started && entries.some((entry) => !entry.isIntersecting)) finish();
    }, { threshold: 0.25, rootMargin: HOME_ENTRANCE_MOTION.rootMargin });

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

    // The complete puzzle remains the SSR/no-JS fallback.
    piece.style.transform = startTransform;
    piece.style.opacity = "0";
    observer.observe(target);
    if (entranceManaged && puzzleReady) {
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
  }, [puzzleReady, skipMotion, entranceManaged]);

  return (
    <svg ref={svgRef} className="block size-full overflow-visible" viewBox="17 17 86 86" fill="none" aria-hidden="true" focusable="false">
      <path d={finalPiece} fill="currentColor" fillOpacity="0.04" stroke="currentColor" strokeOpacity="0.24" strokeWidth="1.5" strokeDasharray="2 3" />
      <g fill="currentColor" stroke="white" strokeWidth="1.5" strokeLinejoin="round">
        <path d="M26 20H60V33C60 34 58 35 56 35C49 35 49 45 56 45C58 45 60 46 60 47V60H47C46 60 45 62 45 64C45 71 35 71 35 64C35 62 34 60 33 60H20V26Q20 20 26 20Z" fillOpacity="0.32" />
        <path d="M20 60H33C34 60 35 62 35 64C35 71 45 71 45 64C45 62 46 60 47 60H60V73C60 74 62 75 64 75C71 75 71 85 64 85C62 85 60 86 60 87V100H26Q20 100 20 94Z" fillOpacity="0.5" />
        <path d="M60 60H73C74 60 75 58 75 56C75 49 85 49 85 56C85 58 86 60 87 60H100V94Q100 100 94 100H60V87C60 86 62 85 64 85C71 85 71 75 64 75C62 75 60 74 60 73Z" fillOpacity="0.72" />
        <g ref={pieceRef} style={{ transformOrigin: "80px 40px" }}>
          <path d={finalPiece} />
        </g>
      </g>
    </svg>
  );
}
