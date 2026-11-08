"use client";

import { useEffect, type RefObject } from "react";

/** After the first viewport exit, playback waits for the existing manual control. */
export function useVisibleVideoPlayback(videoRef: RefObject<HTMLVideoElement | null>, enabled: boolean, onViewportPause: (paused: boolean) => void): void {
  useEffect(() => {
    const video = videoRef.current;
    if (!video) return;
    if (!enabled) {
      video.pause();
      return;
    }

    let visible = false;
    let active = true;
    let pausedAfterExit = false;
    const syncPlayback = (): void => {
      if (!active) return;
      if (visible && !pausedAfterExit && !document.hidden) {
        void video.play().catch(() => undefined);
      } else {
        video.pause();
      }
    };

    const observer = typeof IntersectionObserver === "undefined" ? null : new IntersectionObserver(([entry]) => {
      if (!active) return;
      const nextVisible = Boolean(entry?.isIntersecting);
      if (visible && !nextVisible && !pausedAfterExit) {
        pausedAfterExit = true;
        onViewportPause(true);
      }
      visible = nextVisible;
      syncPlayback();
    }, { threshold: 0 });
    if (observer) {
      observer.observe(video);
    } else {
      visible = true;
      syncPlayback();
    }
    document.addEventListener("visibilitychange", syncPlayback);
    return () => {
      active = false;
      observer?.disconnect();
      document.removeEventListener("visibilitychange", syncPlayback);
      video.pause();
    };
  }, [enabled, videoRef, onViewportPause]);
}
