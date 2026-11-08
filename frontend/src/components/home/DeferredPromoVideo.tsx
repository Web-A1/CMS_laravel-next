"use client";

import { useEffect, useRef, useState } from "react";
import Button from "@/components/button/Button";
import { useMediaQueryState } from "./useMediaQueryState";
import { useVisibleVideoPlayback } from "./useVisibleVideoPlayback";

type DeferredPromoVideoSource = {
  readonly src: string;
  readonly type: string;
};

type DeferredPromoVideoProps = {
  readonly className: string;
  readonly sources: ReadonlyArray<DeferredPromoVideoSource>;
  readonly ariaLabel: string;
};

const reducedMotionQuery = "(prefers-reduced-motion: reduce)";
const mobileQuery = "(width <= 480px)";

export default function DeferredPromoVideo({ className, sources, ariaLabel }: DeferredPromoVideoProps) {
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const motionPreference = useMediaQueryState(reducedMotionQuery);
  const mobileState = useMediaQueryState(mobileQuery);
  const [shouldLoad, setShouldLoad] = useState(false);
  const [isVideoReady, setIsVideoReady] = useState(false);
  const [userPaused, setUserPaused] = useState(false);
  const canPlayVideo = motionPreference === "not-matches" && mobileState === "matches";
  const canLoadVideo = canPlayVideo && shouldLoad;

  useVisibleVideoPlayback(videoRef, canLoadVideo && !userPaused, setUserPaused);

  useEffect(() => {
    const video = videoRef.current;

    if (!canPlayVideo || !video || shouldLoad) {
      return;
    }

    if (typeof IntersectionObserver === "undefined") {
      const timeoutId = window.setTimeout(() => setShouldLoad(true), 0);
      return () => window.clearTimeout(timeoutId);
    }

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry?.isIntersecting) {
          return;
        }

        setShouldLoad(true);
        observer.disconnect();
      },
      { rootMargin: "0px", threshold: 0.01 },
    );

    observer.observe(video);
    return () => observer.disconnect();
  }, [canPlayVideo, shouldLoad]);

  return (
    <div className={className} data-state={isVideoReady && canLoadVideo ? "video" : "preview"}>
      <video
        ref={videoRef}
        className="home-promo-video__video"
        loop
        muted
        playsInline
        preload={canLoadVideo ? "metadata" : "none"}
        aria-label={ariaLabel}
        onLoadedData={() => setIsVideoReady(true)}
        onCanPlay={() => setIsVideoReady(true)}
      >
        {canLoadVideo ? sources.map((source) => <source key={source.src} src={source.src} type={source.type} />) : null}
      </video>
      {canLoadVideo ? (
        <Button type="button" variant="photo" size="md" iconOnly fullWidth={false}
          className="absolute right-3 bottom-3 z-10 pointer-events-auto"
          data-ui="home.promo.video.toggle"
          data-emphasis="quiet"
          aria-label={userPaused ? "Продолжить промовидео" : "Приостановить промовидео"}
          onClick={() => setUserPaused((paused) => !paused)}>
          <svg className="size-4 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
            {userPaused ? <path d="m8 5 11 7-11 7V5Z" /> : <path d="M6 5h4v14H6V5Zm8 0h4v14h-4V5Z" />}
          </svg>
        </Button>
      ) : null}
    </div>
  );
}
