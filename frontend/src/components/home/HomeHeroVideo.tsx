"use client";

import { useRef, useState } from "react";
import Button from "@/components/button/Button";
import { useMediaQueryState } from "./useMediaQueryState";
import { useVisibleVideoPlayback } from "./useVisibleVideoPlayback";

type HomeHeroVideoSource = {
  readonly src: string;
  readonly type: string;
};

type HomeHeroVideoProps = {
  readonly className: string;
  readonly sources: ReadonlyArray<HomeHeroVideoSource>;
};

const nonMobileQuery = "(width > 480px)";
const reducedMotionQuery = "(prefers-reduced-motion: reduce)";

export default function HomeHeroVideo({
  className,
  sources,
}: HomeHeroVideoProps) {
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const [isVideoReady, setIsVideoReady] = useState(false);
  const [userPaused, setUserPaused] = useState(false);
  const nonMobileState = useMediaQueryState(nonMobileQuery);
  const reducedMotionState = useMediaQueryState(reducedMotionQuery);
  const canLoadVideo = nonMobileState === "matches" && reducedMotionState === "not-matches";

  useVisibleVideoPlayback(videoRef, canLoadVideo && !userPaused, setUserPaused);

  return (
    <div className={`${className} relative`} data-state={canLoadVideo && isVideoReady ? "video" : "poster"}>
      <video
        ref={videoRef}
        className="home-hero__media-video"
        loop
        muted
        playsInline
        preload={canLoadVideo ? "metadata" : "none"}
        aria-hidden="true"
        onLoadedData={() => setIsVideoReady(true)}
        onCanPlay={() => setIsVideoReady(true)}
      >
        {canLoadVideo ? sources.map((source) => <source key={source.src} src={source.src} type={source.type} />) : null}
      </video>
      {canLoadVideo ? (
        <Button type="button" variant="photo" size="md" iconOnly fullWidth={false}
          className="absolute right-3 bottom-3 z-10 pointer-events-auto"
          data-ui="home.hero.video.toggle"
          data-emphasis="quiet"
          aria-label={userPaused ? "Продолжить видео в главном блоке" : "Приостановить видео в главном блоке"}
          onClick={() => setUserPaused((paused) => !paused)}>
          <svg className="size-4 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
            {userPaused ? <path d="m8 5 11 7-11 7V5Z" /> : <path d="M6 5h4v14H6V5Zm8 0h4v14h-4V5Z" />}
          </svg>
        </Button>
      ) : null}
    </div>
  );
}
