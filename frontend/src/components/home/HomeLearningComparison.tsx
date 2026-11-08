"use client";

import Image from "next/image";
import { useEffect, useRef, useState, type CSSProperties, type KeyboardEvent, type PointerEvent } from "react";
import Button from "@/components/button/Button";
import { GeneratedImageSources, type StaticGeneratedImageAsset } from "@/components/content/GeneratedImage";
import { HOME_COMPARISON_MOTION, HOME_ENTRANCE_MOTION } from "./homeMotion";
import "./home-learning-comparison.css";
import { academicComparison, schoolComparison } from "./comparisonContent";

const landscapeQuery = "(min-width: 768px) and (orientation: landscape)";
const panoramicQuery = `${landscapeQuery} and (max-height: 840px) and (min-aspect-ratio: 3/2)`;
const shortLandscapeQuery = `${landscapeQuery} and (max-height: 740px) and (min-aspect-ratio: 5/3)`;
const narrowLandscapeQuery = `${landscapeQuery} and (max-aspect-ratio: 13/10)`;
const tabletPortraitQuery = "(min-width: 600px)";
const portraitSizes = "(min-width: 1216px) 1150px, (min-width: 481px) calc(100vw - 4rem - 2px), calc(100vw - 2.5rem - 2px)";

interface MaterialImage {
  src: string;
  panoramicImage?: StaticGeneratedImageAsset;
  shortLandscapeImage?: StaticGeneratedImageAsset;
  narrowLandscapeImage?: StaticGeneratedImageAsset;
  phonePortraitImage: StaticGeneratedImageAsset;
  tabletPortraitImage: StaticGeneratedImageAsset;
  alt: string;
}

interface HomeLearningComparisonProps {
  before: MaterialImage;
  after: MaterialImage;
}

export default function HomeLearningComparison({ before, after }: HomeLearningComparisonProps) {
  const frameRef = useRef<HTMLDivElement>(null);
  const handleRef = useRef<HTMLButtonElement>(null);
  const activePointer = useRef<number | null>(null);
  const cancelIntro = useRef<() => void>(() => {});
  const [position, setPosition] = useState(100);
  const [horizontal, setHorizontal] = useState(false);
  const axisPosition = horizontal ? position : 100 - position;

  useEffect(() => {
    const frame = frameRef.current;
    if (!frame) return;
    const motion = window.matchMedia("(prefers-reduced-motion: reduce)");
    let raf = 0;
    let stopped = false;
    let started = false;
    let observer: IntersectionObserver | undefined;

    function stop() {
      stopped = true;
      cancelAnimationFrame(raf);
      observer?.disconnect();
    }
    cancelIntro.current = stop;

    function reduceMotion() {
      if (!motion.matches) return;
      stop();
      setPosition(50);
    }

    function start() {
      if (stopped || started) return;
      started = true;
      let startedAt: number | undefined;
      let returning = false;
      function tick(now: number) {
        if (stopped) return;
        startedAt ??= now;
        const duration = returning ? HOME_COMPARISON_MOTION.returnMs : HOME_COMPARISON_MOTION.sweepMs;
        const progress = Math.min(1, (now - startedAt) / duration);
        const eased = HOME_COMPARISON_MOTION.progress(progress);
        setPosition(returning ? 50 * eased : 100 * (1 - eased));
        if (progress === 1) {
          if (returning) { stop(); return; }
          returning = true;
          startedAt = now;
        }
        raf = requestAnimationFrame(tick);
      }
      raf = requestAnimationFrame(tick);
    }

    motion.addEventListener("change", reduceMotion);
    if (motion.matches) reduceMotion();
    else if (typeof IntersectionObserver === "undefined") raf = requestAnimationFrame(() => setPosition(50));
    else {
      observer = new IntersectionObserver(entries => {
        if (stopped) return;
        if (entries.some(entry => entry.isIntersecting)) start();
        else if (started) { stop(); setPosition(50); }
      }, { threshold: 0, rootMargin: HOME_ENTRANCE_MOTION.rootMargin });
      observer.observe(frame);
    }
    return () => {
      stop();
      motion.removeEventListener("change", reduceMotion);
    };
  }, []);

  useEffect(() => {
    const media = window.matchMedia(tabletPortraitQuery);
    function updateOrientation() {
      const handle = handleRef.current;
      const pointer = activePointer.current;
      activePointer.current = null;
      if (pointer !== null && handle?.hasPointerCapture(pointer)) handle.releasePointerCapture(pointer);
      setHorizontal(media.matches);
    }
    updateOrientation();
    media.addEventListener("change", updateOrientation);
    return () => media.removeEventListener("change", updateOrientation);
  }, []);

  function moveTo(nextPosition: number) {
    cancelIntro.current();
    if (nextPosition === position) return;
    setPosition(nextPosition);
  }

  function updateFromPointer(event: PointerEvent<HTMLButtonElement>) {
    const rect = frameRef.current?.getBoundingClientRect();
    if (!rect) return;
    const size = horizontal ? rect.width : rect.height;
    if (size <= 0) return;
    const offset = horizontal ? event.clientX - rect.left : event.clientY - rect.top;
    const next = Math.round(Math.min(100, Math.max(0, (offset / size) * 100)));
    moveTo(horizontal ? next : 100 - next);
  }

  function startDrag(event: PointerEvent<HTMLButtonElement>) {
    if (!event.isPrimary || event.button !== 0 || activePointer.current !== null) return;
    event.preventDefault();
    activePointer.current = event.pointerId;
    cancelIntro.current();
    event.currentTarget.focus({ preventScroll: true });
    event.currentTarget.setPointerCapture(event.pointerId);
  }

  function finishDrag(event: PointerEvent<HTMLButtonElement>) {
    if (activePointer.current !== event.pointerId) return;
    activePointer.current = null;
    if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
  }

  function handleKey(event: KeyboardEvent<HTMLButtonElement>) {
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      cancelIntro.current();
      return;
    }
    const step = event.key === "ArrowRight" || event.key === "ArrowDown" ? 1 : event.key === "ArrowLeft" || event.key === "ArrowUp" ? -1 : 0;
    if (event.key === "Home" || event.key === "End") {
      event.preventDefault();
      const next = event.key === "Home" ? 0 : 100;
      moveTo(horizontal ? next : 100 - next);
    } else if (step !== 0) {
      event.preventDefault();
      moveTo(Math.min(100, Math.max(0, position + step * (horizontal ? 1 : -1))));
    }
  }

  function material(image: MaterialImage) {
    return <picture>
      {image.shortLandscapeImage ? <GeneratedImageSources image={image.shortLandscapeImage} media={shortLandscapeQuery} sizes="(min-width: 1216px) 1150px, calc(100vw - 4rem - 2px)" /> : null}
      {image.panoramicImage ? <GeneratedImageSources image={image.panoramicImage} media={panoramicQuery} sizes="(min-width: 1216px) 1150px, calc(100vw - 4rem - 2px)" /> : null}
      {image.narrowLandscapeImage ? <GeneratedImageSources image={image.narrowLandscapeImage} media={narrowLandscapeQuery} sizes="(min-width: 1216px) 1150px, calc(100vw - 4rem - 2px)" /> : null}
      <source media={landscapeQuery} srcSet={image.src} />
      <GeneratedImageSources image={image.tabletPortraitImage} media={tabletPortraitQuery} sizes={portraitSizes} />
      <GeneratedImageSources image={image.phonePortraitImage} sizes={portraitSizes} />
      <Image src={image.phonePortraitImage.webpSrc} width={image.phonePortraitImage.width} height={image.phonePortraitImage.height} alt={image.alt} loading="lazy" unoptimized draggable={false} />
    </picture>;
  }

  return (
    <figure className="home-comparison rounded-[var(--radius-video-frame)] border border-[var(--color-border-brand-subtle)] bg-[var(--color-surface)] shadow-[var(--shadow-card-offset)]" data-ui="home.learningComparison">
      <figcaption className="sr-only">{academicComparison.caption}; {schoolComparison.caption}</figcaption>
      <div className="home-comparison__frame" ref={frameRef} style={{
        "--home-comparison-position": `${position}%`,
      } as CSSProperties}>
        <div className="home-comparison__canvas">
          <div className="home-comparison__layer home-comparison__material">{material(after)}</div>
          <div className="home-comparison__layer home-comparison__layer--before home-comparison__material">{material(before)}</div>
          <span className="home-comparison__line" aria-hidden="true" />
        </div>
        <Button
          ref={handleRef}
          className="home-comparison__handle"
          variant="glass"
          type="button"
          iconOnly
          fullWidth={false}
          role="slider"
          aria-label="Сравнить учебник и конспект"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={axisPosition}
          aria-valuetext={`Учебник ${position}%, конспект ${100 - position}%`}
          aria-orientation={horizontal ? "horizontal" : "vertical"}
          data-ui="home.learningComparison.slider"
          onPointerDown={startDrag}
          onPointerMove={event => { if (activePointer.current === event.pointerId) updateFromPointer(event); }}
          onPointerUp={finishDrag}
          onPointerCancel={finishDrag}
          onLostPointerCapture={event => { if (activePointer.current === event.pointerId) activePointer.current = null; }}
          onKeyDown={handleKey}
          onClick={() => cancelIntro.current()}
        >
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true">
            <path d="m8 6-6 6 6 6m8-12 6 6-6 6" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </Button>
      </div>
    </figure>
  );
}
