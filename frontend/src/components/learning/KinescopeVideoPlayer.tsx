"use client";

import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from "react";

import Button from "@/components/button/Button";
import TextLink from "@/components/content/TextLink";
import {
  parsePlaybackResponse,
  type PlaybackData,
  type PlaybackContractResult,
} from "@/components/learning/kinescopePlaybackContract";
import {
  bindStoppedVideoIdleEvents,
  finishStoppedVideoIdleLogout,
  StoppedVideoIdleCoordinator,
} from "@/components/learning/stoppedVideoIdleCoordinator";
import { logoutClientAccountAfterIdleTimeout } from "@/lib/clientAccount";
import type { LearningLessonMediaState, LearningVideoMaterial } from "@/lib/learningApi";
import { trustedBrowserProtectedErrorCode } from "@/lib/trustedBrowserApi";
import type { TrustedBrowserProtectedErrorCode } from "@/lib/trustedBrowserApi";

type KinescopeVideoPlayerProps = {
  readonly apiBaseUrl: string;
  readonly courseSlug: string;
  readonly initialState: LearningLessonMediaState;
  readonly lessonSlug: string;
  readonly subjectSlug: string;
};

type PlaybackPositionData = {
  readonly resumePositionSeconds: number | null;
};

type JsonRecord = Record<string, unknown>;

type PlaybackState =
  | { readonly status: "idle" }
  | { readonly status: "loading" }
  | { readonly status: "ready"; readonly data: PlaybackData }
  | { readonly status: "denied" }
  | { readonly status: "trusted_browser_recovery"; readonly code: TrustedBrowserProtectedErrorCode }
  | { readonly status: "provider_not_configured" }
  | { readonly status: "unavailable" };

type PlaybackRequestResult = PlaybackContractResult | {
  readonly status: "trusted_browser_recovery";
  readonly code: TrustedBrowserProtectedErrorCode;
};

type PlaybackPositionEvent = "ended" | "pause";

type KinescopeEventName = "Ended" | "Pause" | "Playing" | "Ready";

type KinescopeWatermarkOptions = {
  readonly mode: "random";
  readonly text: string;
};

type KinescopePlayerOptions = {
  readonly size?: { readonly height: string; readonly width: string };
  readonly ui?: {
    readonly watermark?: KinescopeWatermarkOptions;
  };
  readonly url: string;
};

type KinescopePlayer = {
  readonly Events: Readonly<Record<KinescopeEventName, string>>;
  destroy?(): void;
  getCurrentTime(): Promise<number>;
  getDuration(): Promise<number>;
  off?(eventName: string, handler: () => void): void;
  on(eventName: string, handler: () => void): void;
  once?(eventName: string, handler: () => void): void;
  seekTo(seconds: number): Promise<void>;
  setOptions?(options: Pick<KinescopePlayerOptions, "ui">): Promise<void> | void;
};

type KinescopePlayerFactory = {
  create(elementId: string, options: KinescopePlayerOptions): Promise<KinescopePlayer>;
};

declare global {
  interface Window {
    onKinescopeIframeAPIReady?: (playerFactory: KinescopePlayerFactory) => void;
  }
}

const KINESCOPE_IFRAME_API_SRC = "https://player.kinescope.io/latest/iframe.player.js";
const PLAYBACK_REFRESH_LEEWAY_MS = 60_000;
const PLAYBACK_REFRESH_JITTER_MIN_MS = 5_000;
const PLAYBACK_REFRESH_JITTER_MAX_MS = 20_000;
const PLAYBACK_EXPIRE_GRACE_MS = 1_000;
const VIDEO_FRAME_HEIGHT_UNITS = 9;
const VIDEO_FRAME_WIDTH_UNITS = 16;

let kinescopePlayerFactory: KinescopePlayerFactory | null = null;
let kinescopePlayerFactoryPromise: Promise<KinescopePlayerFactory> | null = null;

function usePixelSizedVideoFrame() {
  const videoFrameRef = useRef<HTMLDivElement | null>(null);
  const [videoFrameHeight, setVideoFrameHeight] = useState<number | null>(null);

  useLayoutEffect(() => {
    const videoFrame = videoFrameRef.current;
    if (!videoFrame) {
      return undefined;
    }

    function updateHeight(width: number): void {
      if (!Number.isFinite(width) || width <= 0) {
        return;
      }

      const nextHeight = Math.round((width * VIDEO_FRAME_HEIGHT_UNITS) / VIDEO_FRAME_WIDTH_UNITS);
      setVideoFrameHeight((currentHeight) => (
        currentHeight === nextHeight ? currentHeight : nextHeight
      ));
    }

    updateHeight(videoFrame.getBoundingClientRect().width);

    const resizeObserver = new ResizeObserver((entries) => {
      updateHeight(entries[0]?.contentRect.width ?? videoFrame.getBoundingClientRect().width);
    });
    resizeObserver.observe(videoFrame);

    return () => resizeObserver.disconnect();
  }, []);

  return { videoFrameHeight, videoFrameRef };
}

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function hasExactKeys(record: JsonRecord, required: ReadonlyArray<string>): boolean {
  const allowed = new Set(required);
  const keys = Object.keys(record);

  return required.every((key) => Object.hasOwn(record, key)) && keys.every((key) => allowed.has(key));
}

function readXsrfToken(): string {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  if (!match?.[1]) {
    return "";
  }

  try {
    return decodeURIComponent(match[1]);
  } catch {
    return "";
  }
}

function isNonNegativeFiniteNumber(value: unknown): value is number {
  return typeof value === "number" && Number.isFinite(value) && value >= 0;
}

function isPlaybackPositionPayload(value: unknown): value is { readonly data: PlaybackPositionData } {
  if (!isRecord(value) || !hasExactKeys(value, ["data"]) || !isRecord(value.data)) {
    return false;
  }

  const data = value.data;

  return (
    hasExactKeys(data, ["resumePositionSeconds"]) &&
    (data.resumePositionSeconds === null || isNonNegativeFiniteNumber(data.resumePositionSeconds))
  );
}

function playbackPath(subjectSlug: string, courseSlug: string, lessonSlug: string): string {
  return [
    "/api/v1/learning/subjects",
    encodeURIComponent(subjectSlug),
    "courses",
    encodeURIComponent(courseSlug),
    "lessons",
    encodeURIComponent(lessonSlug),
    "video-playback",
  ].join("/");
}

function positionPath(subjectSlug: string, courseSlug: string, lessonSlug: string): string {
  return [
    "/api/v1/learning/subjects",
    encodeURIComponent(subjectSlug),
    "courses",
    encodeURIComponent(courseSlug),
    "lessons",
    encodeURIComponent(lessonSlug),
    "video-position",
  ].join("/");
}

function playbackExpiresAtMs(playbackData: PlaybackData): number {
  return Date.parse(playbackData.expiresAt);
}

function playbackRefreshJitterMs(): number {
  return PLAYBACK_REFRESH_JITTER_MIN_MS + Math.random() * (PLAYBACK_REFRESH_JITTER_MAX_MS - PLAYBACK_REFRESH_JITTER_MIN_MS);
}

function playbackRefreshDelayMs(playbackData: PlaybackData, now = Date.now(), jitterMs = playbackRefreshJitterMs()): number {
  return Math.max(0, playbackExpiresAtMs(playbackData) - now - PLAYBACK_REFRESH_LEEWAY_MS - jitterMs);
}

function playbackExpireDelayMs(playbackData: PlaybackData, now = Date.now()): number {
  return Math.max(0, playbackExpiresAtMs(playbackData) - now + PLAYBACK_EXPIRE_GRACE_MS);
}

function isPlaybackRefreshDue(playbackData: PlaybackData, now = Date.now()): boolean {
  return playbackExpiresAtMs(playbackData) - now <= PLAYBACK_REFRESH_LEEWAY_MS;
}

function isPlaybackExpired(playbackData: PlaybackData, now = Date.now()): boolean {
  return playbackExpiresAtMs(playbackData) <= now;
}

function extendCurrentPlaybackLease(currentData: PlaybackData, refreshedData: PlaybackData): PlaybackData {
  return {
    ...refreshedData,
    embedUrl: currentData.embedUrl,
  };
}

function kinescopeWatermarkOptions(text: string): KinescopeWatermarkOptions {
  return { text, mode: "random" };
}

function kinescopePlayerOptions(embedUrlValue: string): KinescopePlayerOptions {
  const options: KinescopePlayerOptions = {
    size: { height: "100%", width: "100%" },
    url: embedUrlValue,
  };

  const embedUrl = new URL(embedUrlValue);
  const watermark = embedUrl.searchParams.get("watermark");
  if (watermark === null) {
    return options;
  }

  embedUrl.searchParams.delete("watermark");

  return {
    ...options,
    ui: {
      watermark: kinescopeWatermarkOptions(watermark),
    },
    url: embedUrl.toString(),
  };
}

async function csrfHeaders(normalizedApiBaseUrl: string): Promise<Record<string, string>> {
  let xsrfToken = readXsrfToken();

  if (xsrfToken === "") {
    await fetch(`${normalizedApiBaseUrl}/sanctum/csrf-cookie`, {
      method: "GET",
      credentials: "include",
      headers: { Accept: "application/json" },
    });
    xsrfToken = readXsrfToken();
  }

  return xsrfToken === "" ? {} : { "X-XSRF-TOKEN": xsrfToken };
}

function loadKinescopePlayerFactory(): Promise<KinescopePlayerFactory> {
  if (kinescopePlayerFactory) {
    return Promise.resolve(kinescopePlayerFactory);
  }

  if (kinescopePlayerFactoryPromise) {
    return kinescopePlayerFactoryPromise;
  }

  kinescopePlayerFactoryPromise = new Promise((resolve, reject) => {
    const previousReadyHandler = window.onKinescopeIframeAPIReady;
    let settled = false;
    const timeout = window.setTimeout(() => {
      if (!settled) {
        settled = true;
        reject(new Error("Kinescope IFrame API did not become ready."));
      }
    }, 15000);

    window.onKinescopeIframeAPIReady = (playerFactory) => {
      try {
        previousReadyHandler?.(playerFactory);
      } catch {
        // Another handler must not prevent this player from becoming available.
      }
      if (settled) {
        return;
      }

      settled = true;
      window.clearTimeout(timeout);
      kinescopePlayerFactory = playerFactory;
      resolve(playerFactory);
    };

    const existingScript = document.querySelector<HTMLScriptElement>(`script[src="${KINESCOPE_IFRAME_API_SRC}"]`);
    if (existingScript) {
      existingScript.addEventListener(
        "error",
        () => {
          if (!settled) {
            settled = true;
            window.clearTimeout(timeout);
            reject(new Error("Kinescope IFrame API failed to load."));
          }
        },
        { once: true },
      );
      return;
    }

    const script = document.createElement("script");
    script.async = true;
    script.src = KINESCOPE_IFRAME_API_SRC;
    script.addEventListener(
      "error",
      () => {
        if (!settled) {
          settled = true;
          window.clearTimeout(timeout);
          reject(new Error("Kinescope IFrame API failed to load."));
        }
      },
      { once: true },
    );
    document.head.append(script);
  });
  kinescopePlayerFactoryPromise.catch(() => {
    kinescopePlayerFactoryPromise = null;
  });

  return kinescopePlayerFactoryPromise;
}

function createKinescopeMountElement(elementId: string): HTMLDivElement {
  const mountElement = document.createElement("div");
  mountElement.id = elementId;
  mountElement.className = "h-full w-full";
  mountElement.setAttribute("data-ui", "lk.learning.lessonVideo.playerMount");

  return mountElement;
}

function cleanupKinescopeDom(player: KinescopePlayer | null, mountElement: HTMLDivElement | null): void {
  try {
    player?.destroy?.();
  } catch {
    // Kinescope owns iframe DOM and may already have removed it during dev reload or provider failure.
  }

  try {
    mountElement?.remove();
  } catch {
    // Keep teardown best-effort: external SDK mutations must not crash React cleanup.
  }
}

function detachKinescopeHandler(player: KinescopePlayer | null, eventName: string, handler: () => void): void {
  try {
    player?.off?.(eventName, handler);
  } catch {
    // Event teardown is owned by the external SDK and must stay best-effort.
  }
}

function trustedBrowserVideoRecoveryText(code: TrustedBrowserProtectedErrorCode): string {
  switch (code) {
    case "TRUSTED_BROWSER_REQUIRED":
      return "Подтвердите этот браузер, чтобы открыть видео.";
    case "TRUSTED_BROWSER_SUPPORT_REQUIRED":
      return "Для этого браузера требуется восстановление доступа через поддержку.";
    case "TRUSTED_BROWSER_RISK_LOCKED":
      return "Доступ к видео для этого браузера приостановлен.";
  }
}

function PlaybackStatus({ state }: { readonly state: Exclude<PlaybackState, { readonly status: "ready" }> }) {
  switch (state.status) {
    case "denied":
      return <p className="text-sm leading-6 ui-text-secondary">Доступ к видео не подтверждён.</p>;
    case "loading":
      return <p className="text-sm leading-6 ui-text-secondary">Подготавливаем защищённое воспроизведение…</p>;
    case "provider_not_configured":
      return <p className="text-sm leading-6 ui-text-secondary">Видеосервис пока не настроен.</p>;
    case "unavailable":
      return <p className="text-sm leading-6 ui-text-secondary">Видео временно недоступно. Попробуйте ещё раз.</p>;
    case "trusted_browser_recovery": {
      return (
        <div className="grid justify-items-center gap-3" data-ui="lk.learning.lessonVideo.trustedBrowserRecovery">
          <p className="text-sm leading-6 ui-text-secondary">{trustedBrowserVideoRecoveryText(state.code)}</p>
          <TextLink href="/lk#trusted-browsers">Проверить доверенные браузеры</TextLink>
        </div>
      );
    }
    case "idle":
      return <p className="text-sm leading-6 ui-text-secondary">Подготавливаем защищённое воспроизведение…</p>;
  }
}

function KinescopeIframePlayer({
  courseSlug,
  lessonSlug,
  idleCoordinator,
  material,
  normalizedApiBaseUrl,
  onResumePositionChange,
  onUnavailable,
  playbackData,
  resumePositionSeconds,
  subjectSlug,
}: {
  readonly courseSlug: string;
  readonly idleCoordinator: StoppedVideoIdleCoordinator;
  readonly lessonSlug: string;
  readonly material: LearningVideoMaterial;
  readonly normalizedApiBaseUrl: string;
  readonly onResumePositionChange: (resumePositionSeconds: number | null) => void;
  readonly onUnavailable: () => void;
  readonly playbackData: PlaybackData;
  readonly resumePositionSeconds: number | null;
  readonly subjectSlug: string;
}) {
  const reactId = useId();
  const baseElementId = `kinescope-player-${reactId.replace(/[^A-Za-z0-9_-]/g, "")}`;
  const containerRef = useRef<HTMLDivElement | null>(null);
  const playerMountSequenceRef = useRef(0);
  const onResumePositionChangeRef = useRef(onResumePositionChange);
  const onUnavailableRef = useRef(onUnavailable);
  const resumePositionRef = useRef(resumePositionSeconds);
  const saveAbortControllerRef = useRef<AbortController | null>(null);

  useEffect(() => {
    onResumePositionChangeRef.current = onResumePositionChange;
  }, [onResumePositionChange]);

  useEffect(() => {
    onUnavailableRef.current = onUnavailable;
  }, [onUnavailable]);

  useEffect(() => {
    resumePositionRef.current = resumePositionSeconds;
  }, [resumePositionSeconds]);

  useEffect(() => {
    let canceled = false;
    let player: KinescopePlayer | null = null;
    const containerElement = containerRef.current;
    if (!containerElement) {
      onUnavailableRef.current();
      return;
    }

    playerMountSequenceRef.current += 1;
    const playerElementId = `${baseElementId}-${playerMountSequenceRef.current}`;
    const mountElement = createKinescopeMountElement(playerElementId);
    containerElement.append(mountElement);

    function detachPlayerHandlers(): void {
      if (!player) {
        return;
      }

      detachKinescopeHandler(player, player.Events.Pause, handleStoppedPlayback);
      detachKinescopeHandler(player, player.Events.Ended, handleStoppedPlayback);
      detachKinescopeHandler(player, player.Events.Playing, handlePlayingPlayback);
      detachKinescopeHandler(player, player.Events.Pause, handlePause);
      detachKinescopeHandler(player, player.Events.Ended, handleEnded);
    }

    function cleanupPlayerForIdleLogout(): void {
      canceled = true;
      saveAbortControllerRef.current?.abort();
      detachPlayerHandlers();
      cleanupKinescopeDom(player, mountElement);
    }

    const unregisterIdlePlayer = idleCoordinator.registerPlayer(baseElementId, cleanupPlayerForIdleLogout);

    async function saveVideoPosition(
      event: PlaybackPositionEvent,
      positionSeconds?: number,
      durationSeconds?: number,
    ): Promise<void> {
      saveAbortControllerRef.current?.abort();
      const abortController = new AbortController();
      saveAbortControllerRef.current = abortController;

      try {
        const xsrfHeaders = await csrfHeaders(normalizedApiBaseUrl);
        const response = await fetch(
          `${normalizedApiBaseUrl}${positionPath(subjectSlug, courseSlug, lessonSlug)}`,
          {
            method: "POST",
            credentials: "include",
            keepalive: true,
            signal: abortController.signal,
            headers: {
              Accept: "application/json",
              "Content-Type": "application/json",
              ...xsrfHeaders,
            },
            body: JSON.stringify({
              durationSeconds,
              event,
              materialId: material.materialId,
              ...(event === "pause" ? { positionSeconds } : {}),
            }),
          },
        );
        const payload: unknown = await response.json().catch(() => null);

        if (!canceled && response.ok && isPlaybackPositionPayload(payload)) {
          onResumePositionChangeRef.current(payload.data.resumePositionSeconds);
        }
      } catch (error) {
        if (!(error instanceof DOMException && error.name === "AbortError")) {
          return;
        }
      } finally {
        if (saveAbortControllerRef.current === abortController) {
          saveAbortControllerRef.current = null;
        }
      }
    }

    async function savePausePosition(): Promise<void> {
      if (!player) {
        return;
      }

      const positionSeconds = await player.getCurrentTime();
      const durationSeconds = await player.getDuration().catch(() => undefined);
      await saveVideoPosition("pause", positionSeconds, durationSeconds);
    }

    async function resetEndedPosition(): Promise<void> {
      if (!player) {
        return;
      }

      const durationSeconds = await player.getDuration().catch(() => undefined);
      await saveVideoPosition("ended", undefined, durationSeconds);
    }

    function handlePause(): void {
      void savePausePosition().catch(() => undefined);
    }

    function handleEnded(): void {
      void resetEndedPosition().catch(() => undefined);
    }

    function handleStoppedPlayback(): void {
      idleCoordinator.markStopped(baseElementId);
    }

    function handlePlayingPlayback(): void {
      idleCoordinator.markPlaying(baseElementId);
    }

    async function mountPlayer(): Promise<void> {
      try {
        const playerFactory = await loadKinescopePlayerFactory();
        if (canceled) {
          return;
        }

        const playerOptions = kinescopePlayerOptions(playbackData.embedUrl);
        player = await playerFactory.create(playerElementId, playerOptions);

        if (canceled) {
          cleanupKinescopeDom(player, mountElement);
          return;
        }

        if (playerOptions.ui) {
          void Promise.resolve(player.setOptions?.({ ui: playerOptions.ui })).catch(() => undefined);
        }

        player.on(player.Events.Pause, handleStoppedPlayback);
        player.on(player.Events.Ended, handleStoppedPlayback);
        player.on(player.Events.Playing, handlePlayingPlayback);
        player.on(player.Events.Pause, handlePause);
        player.on(player.Events.Ended, handleEnded);

        const seekToAccountResumePosition = (): void => {
          const accountResumePositionSeconds = resumePositionRef.current ?? 0;
          void player?.seekTo(accountResumePositionSeconds).catch(() => undefined);
        };

        if (player.once) {
          player.once(player.Events.Ready, seekToAccountResumePosition);
        } else {
          seekToAccountResumePosition();
        }
      } catch {
        if (!canceled) {
          onUnavailableRef.current();
        }
      }
    }

    void mountPlayer();

    return () => {
      unregisterIdlePlayer();
      cleanupPlayerForIdleLogout();
    };
  }, [baseElementId, courseSlug, idleCoordinator, lessonSlug, material.materialId, normalizedApiBaseUrl, playbackData.embedUrl, subjectSlug]);

  return <div ref={containerRef} className="h-full w-full" data-ui="lk.learning.lessonVideo.player" />;
}

function LessonVideo({
  apiBaseUrl,
  courseSlug,
  idleCoordinator,
  lessonSlug,
  material,
  subjectSlug,
}: Omit<KinescopeVideoPlayerProps, "initialState"> & {
  readonly idleCoordinator: StoppedVideoIdleCoordinator;
  readonly material: LearningVideoMaterial;
}) {
  const [playback, setPlayback] = useState<PlaybackState>({ status: "loading" });
  const [resumePositionSeconds, setResumePositionSeconds] = useState(material.resumePositionSeconds);
  const playbackRef = useRef<PlaybackState>({ status: "loading" });
  const normalizedApiBaseUrl = apiBaseUrl.replace(/\/+$/, "");
  const { videoFrameHeight, videoFrameRef } = usePixelSizedVideoFrame();
  const videoFrameStyle = videoFrameHeight === null ? undefined : { height: videoFrameHeight };

  useEffect(() => {
    playbackRef.current = playback;
  }, [playback]);

  const loadPlayback = useCallback(async (): Promise<PlaybackRequestResult> => {
    try {
      const xsrfHeaders = await csrfHeaders(normalizedApiBaseUrl);
      const response = await fetch(
        `${normalizedApiBaseUrl}${playbackPath(subjectSlug, courseSlug, lessonSlug)}`,
        {
          method: "POST",
          credentials: "include",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            ...xsrfHeaders,
          },
          body: JSON.stringify({ materialId: material.materialId }),
        },
      );
      const payload: unknown = await response.json().catch(() => null);
      const trustedBrowserCode = response.status === 403 ? trustedBrowserProtectedErrorCode(payload) : null;

      if (trustedBrowserCode) {
        return { status: "trusted_browser_recovery", code: trustedBrowserCode };
      }

      return parsePlaybackResponse(response.status, payload);
    } catch {
      return { status: "unavailable" };
    }
  }, [courseSlug, lessonSlug, material.materialId, normalizedApiBaseUrl, subjectSlug]);

  const refreshReadyPlayback = useCallback(async function refreshReadyPlayback(sourceEmbedUrl: string): Promise<void> {
    const result = await loadPlayback();
    const now = Date.now();

    setPlayback((current) => {
      if (current.status !== "ready" || current.data.embedUrl !== sourceEmbedUrl) {
        return current;
      }
      if (result.status === "ready") {
        return { status: "ready", data: extendCurrentPlaybackLease(current.data, result.data) };
      }
      if (
        result.status === "denied" ||
        result.status === "provider_not_configured" ||
        result.status === "trusted_browser_recovery"
      ) {
        return result;
      }
      if (isPlaybackExpired(current.data, now)) {
        return result;
      }

      return current;
    });
  }, [loadPlayback]);

  const requestPlaybackSilently = useCallback(async function requestPlaybackSilently(): Promise<void> {
    setPlayback(await loadPlayback());
  }, [loadPlayback]);

  useEffect(() => {
    let active = true;

    void loadPlayback().then((result) => {
      if (active) {
        setPlayback(result);
      }
    });

    return () => {
      active = false;
    };
  }, [loadPlayback]);

  useEffect(() => {
    if (playback.status !== "ready") {
      return;
    }

    const sourceEmbedUrl = playback.data.embedUrl;
    const refreshTimer = window.setTimeout(() => {
      if (document.visibilityState !== "visible") {
        return;
      }

      void refreshReadyPlayback(sourceEmbedUrl);
    }, playbackRefreshDelayMs(playback.data));
    const expireTimer = window.setTimeout(() => {
      setPlayback((current) =>
        current.status === "ready" && current.data.embedUrl === sourceEmbedUrl && isPlaybackExpired(current.data)
          ? { status: "unavailable" }
          : current,
      );
    }, playbackExpireDelayMs(playback.data));

    return () => {
      window.clearTimeout(refreshTimer);
      window.clearTimeout(expireTimer);
    };
  }, [playback, refreshReadyPlayback]);

  useEffect(() => {
    function refreshVisiblePlayback(): void {
      if (document.visibilityState !== "visible") {
        return;
      }

      const current = playbackRef.current;
      if (current.status === "ready" && isPlaybackRefreshDue(current.data)) {
        void refreshReadyPlayback(current.data.embedUrl);
        return;
      }
      if (current.status === "unavailable") {
        void requestPlaybackSilently();
      }
    }

    document.addEventListener("visibilitychange", refreshVisiblePlayback);

    return () => document.removeEventListener("visibilitychange", refreshVisiblePlayback);
  }, [refreshReadyPlayback, requestPlaybackSilently]);

  const requestPlayback = useCallback(async (): Promise<void> => {
    setPlayback({ status: "loading" });
    setPlayback(await loadPlayback());
  }, [loadPlayback]);

  const hasPlayer = playback.status === "ready";

  return (
    <section
      className="grid gap-2"
      data-ui="lk.learning.lessonVideo"
      data-state={playback.status}
    >
      <h3 className="sr-only">{material.title}</h3>

      <div className="grid gap-2">
        <div
          ref={videoFrameRef}
          className={hasPlayer ? "w-full bg-black" : "w-full bg-[var(--color-surface-subtle)]"}
          data-layout="pixel-sized-16x9"
          style={videoFrameStyle}
        >
          {hasPlayer ? (
            <KinescopeIframePlayer
              courseSlug={courseSlug}
              idleCoordinator={idleCoordinator}
              lessonSlug={lessonSlug}
              material={material}
              normalizedApiBaseUrl={normalizedApiBaseUrl}
              onResumePositionChange={setResumePositionSeconds}
              onUnavailable={() => setPlayback({ status: "unavailable" })}
              playbackData={playback.data}
              resumePositionSeconds={resumePositionSeconds}
              subjectSlug={subjectSlug}
            />
          ) : (
            <div className="flex h-full flex-col items-center justify-center gap-3 p-5 text-center">
              <PlaybackStatus state={playback} />
              {playback.status === "unavailable" ? (
                <Button
                  type="button"
                  fullWidth={false}
                  className="w-full sm:w-fit"
                  onClick={requestPlayback}
                >
                  Повторить попытку
                </Button>
              ) : null}
            </div>
          )}
        </div>
      </div>
    </section>
  );
}

export default function KinescopeVideoPlayer({
  apiBaseUrl,
  courseSlug,
  initialState,
  lessonSlug,
  subjectSlug,
}: KinescopeVideoPlayerProps) {
  const normalizedApiBaseUrl = apiBaseUrl.replace(/\/+$/, "");
  const [idleCoordinator] = useState(
    () =>
      new StoppedVideoIdleCoordinator({
      onIdleTimeout: () =>
        finishStoppedVideoIdleLogout({
          location: window.location,
          logout: () => logoutClientAccountAfterIdleTimeout(normalizedApiBaseUrl),
        }),
      }),
  );

  useEffect(() => {
    const unbindStoppedVideoIdleEvents = bindStoppedVideoIdleEvents(idleCoordinator);

    return () => {
      unbindStoppedVideoIdleEvents();
      idleCoordinator.dispose();
    };
  }, [idleCoordinator]);

  if (initialState.status === "ready" && initialState.videos.length === 0) {
    return null;
  }

  if (initialState.status !== "ready") {
    const message =
      initialState.status === "denied"
        ? "Доступ к видео не подтверждён."
        : "Не удалось загрузить список видео. Попробуйте позже.";

    return (
      <section
        className="rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-white p-4"
        data-ui="lk.learning.lessonVideos"
        data-state={initialState.status}
      >
        <p className="text-sm leading-6 ui-text-secondary">{message}</p>
      </section>
    );
  }

  return (
    <section className="grid gap-4" aria-labelledby="lk-learning-videos-title" data-ui="lk.learning.lessonVideos" data-state="ready">
      <h2 id="lk-learning-videos-title" className="sr-only">
        Видео урока
      </h2>
      {initialState.videos.map((video) => (
        <LessonVideo
          key={video.materialId}
          apiBaseUrl={apiBaseUrl}
          subjectSlug={subjectSlug}
          courseSlug={courseSlug}
          idleCoordinator={idleCoordinator}
          lessonSlug={lessonSlug}
          material={video}
        />
      ))}
    </section>
  );
}
