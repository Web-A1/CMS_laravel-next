import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const componentPath = new URL("./KinescopeVideoPlayer.tsx", import.meta.url);
const coordinatorModulePath = "./stoppedVideoIdleCoordinator.ts";
const coordinator: typeof import("./stoppedVideoIdleCoordinator") = await import(coordinatorModulePath);

type FakeTimer = {
  readonly dueAt: number;
  readonly handler: () => void;
};

function createFakeClock() {
  let now = 0;
  let nextTimerId = 1;
  let clearTimeoutCalls = 0;
  let setTimeoutCalls = 0;
  const timers = new Map<number, FakeTimer>();

  return {
    clearTimeout(timerId: number): void {
      clearTimeoutCalls += 1;
      timers.delete(timerId);
    },
    now: (): number => now,
    setTimeout(handler: () => void, delayMs: number): number {
      setTimeoutCalls += 1;
      const timerId = nextTimerId;
      nextTimerId += 1;
      timers.set(timerId, { dueAt: now + delayMs, handler });
      return timerId;
    },
    async advanceBy(milliseconds: number): Promise<void> {
      now += milliseconds;
      while (true) {
        const dueTimer = Array.from(timers.entries())
          .filter(([, timer]) => timer.dueAt <= now)
          .sort(([firstId], [secondId]) => firstId - secondId)[0];
        if (!dueTimer) {
          break;
        }

        const [timerId, timer] = dueTimer;
        timers.delete(timerId);
        timer.handler();
        await Promise.resolve();
      }
    },
    advanceWithoutRunningTimers(milliseconds: number): void {
      now += milliseconds;
    },
    get clearTimeoutCalls(): number {
      return clearTimeoutCalls;
    },
    get setTimeoutCalls(): number {
      return setTimeoutCalls;
    },
    get timerCount(): number {
      return timers.size;
    },
  };
}

function createFakeEventTarget() {
  const listeners = new Map<string, Set<EventListener>>();
  const target = {
    addEventListener(eventName: string, listener: EventListenerOrEventListenerObject | null): void {
      if (typeof listener !== "function") {
        return;
      }

      const handlers = listeners.get(eventName) ?? new Set<EventListener>();
      handlers.add(listener);
      listeners.set(eventName, handlers);
    },
    removeEventListener(eventName: string, listener: EventListenerOrEventListenerObject | null): void {
      if (typeof listener === "function") {
        listeners.get(eventName)?.delete(listener);
      }
    },
  } as EventTarget;

  return {
    dispatch(eventName: string): void {
      for (const listener of listeners.get(eventName) ?? []) {
        listener(new Event(eventName));
      }
    },
    listenerCount(eventName: string): number {
      return listeners.get(eventName)?.size ?? 0;
    },
    target,
  };
}

test("opens lesson video playback automatically without the initial watch button", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /useState<PlaybackState>\(\{ status: "loading" \}\)/);
  assert.match(source, /void loadPlayback\(\)\.then/);
  assert.match(source, /<h3 className="sr-only">\{material\.title\}<\/h3>/);
  assert.doesNotMatch(source, /data-ui="lk\.learning\.lessonVideo\.resume"/);
  assert.doesNotMatch(source, /Продолжим с/);
  assert.doesNotMatch(source, /formatPlaybackTime/);
  assert.match(source, /function usePixelSizedVideoFrame\(\)/);
  assert.match(source, /new ResizeObserver/);
  assert.match(source, /data-layout="pixel-sized-16x9"/);
  assert.doesNotMatch(source, /videoFrameClassName/);
  assert.doesNotMatch(source, /aspect-video/);
  assert.doesNotMatch(source, /overflow-hidden/);
  assert.doesNotMatch(source, /padding-top|backdrop-filter|border-radius/);
  assert.match(source, /function cleanupKinescopeDom/);
  assert.match(source, /function detachKinescopeHandler/);
  assert.doesNotMatch(source, /Смотреть видео/);
  assert.doesNotMatch(source, /Срок данных воспроизведения истёк/);
  assert.doesNotMatch(source, /Обновить доступ к видео/);
  assert.doesNotMatch(source, /text-lg font-semibold leading-tight text-zinc-900">\{material\.title\}/);
  assert.match(source, /Повторить попытку/);
});

test("mounts the Kinescope SDK into an imperative child node", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /function createKinescopeMountElement\(elementId: string\): HTMLDivElement/);
  assert.match(source, /containerElement\.append\(mountElement\)/);
  assert.match(source, /playerFactory\.create\(playerElementId,/);
  assert.match(source, /mountElement\?\.remove\(\)/);
  assert.match(source, /data-ui", "lk\.learning\.lessonVideo\.playerMount"/);
  assert.doesNotMatch(source, /containerElement\?\.replaceChildren\(\)/);
});

test("passes video watermark text to Kinescope API as a random overlay", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /type KinescopeWatermarkOptions = \{/);
  assert.match(source, /readonly mode: "random"/);
  assert.match(source, /function kinescopePlayerOptions\(embedUrlValue: string\): KinescopePlayerOptions/);
  assert.match(source, /const watermark = embedUrl\.searchParams\.get\("watermark"\)/);
  assert.match(source, /embedUrl\.searchParams\.delete\("watermark"\)/);
  assert.match(source, /watermark: kinescopeWatermarkOptions\(watermark\)/);
  assert.match(source, /const playerOptions = kinescopePlayerOptions\(playbackData\.embedUrl\)/);
  assert.match(source, /player = await playerFactory\.create\(playerElementId, playerOptions\)/);
  assert.match(source, /void Promise\.resolve\(player\.setOptions\?\.\(\{ ui: playerOptions\.ui \}\)\)\.catch\(\(\) => undefined\)/);
  assert.doesNotMatch(source, /url: playbackData\.embedUrl,\s*\}\);/);
});

test("forces Kinescope to the current account resume position on ready", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /const seekToAccountResumePosition = \(\): void => \{/);
  assert.match(source, /const accountResumePositionSeconds = resumePositionRef\.current \?\? 0/);
  assert.match(source, /void player\?\.seekTo\(accountResumePositionSeconds\)\.catch\(\(\) => undefined\)/);
  assert.match(source, /player\.once\(player\.Events\.Ready, seekToAccountResumePosition\)/);
  assert.doesNotMatch(source, /savedPositionSeconds !== null && savedPositionSeconds > 0/);
});

test("refreshes protected playback data before expiry and on tab return", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /const PLAYBACK_REFRESH_LEEWAY_MS = 60_000/);
  assert.match(source, /const PLAYBACK_REFRESH_JITTER_MIN_MS = 5_000/);
  assert.match(source, /const PLAYBACK_REFRESH_JITTER_MAX_MS = 20_000/);
  assert.match(source, /function playbackRefreshDelayMs\(playbackData: PlaybackData, now = Date\.now\(\), jitterMs = playbackRefreshJitterMs\(\)\): number/);
  assert.match(source, /function isPlaybackRefreshDue\(playbackData: PlaybackData, now = Date\.now\(\)\): boolean/);
  assert.match(source, /function extendCurrentPlaybackLease\(currentData: PlaybackData, refreshedData: PlaybackData\): PlaybackData/);
  assert.match(source, /embedUrl: currentData\.embedUrl/);
  assert.match(source, /const refreshReadyPlayback = useCallback\(async function refreshReadyPlayback\(sourceEmbedUrl: string\): Promise<void>/);
  assert.match(source, /return \{ status: "ready", data: extendCurrentPlaybackLease\(current\.data, result\.data\) \}/);
  assert.match(source, /result\.status === "denied"/);
  assert.match(source, /result\.status === "provider_not_configured"/);
  assert.match(source, /result\.status === "trusted_browser_recovery"/);
  assert.match(source, /document\.visibilityState !== "visible"/);
  assert.match(source, /window\.setTimeout\(\(\) => \{[\s\S]*document\.visibilityState !== "visible"[\s\S]*void refreshReadyPlayback\(sourceEmbedUrl\);[\s\S]*playbackRefreshDelayMs\(playback\.data\)/);
  assert.match(source, /current\.status === "ready" && current\.data\.embedUrl === sourceEmbedUrl && isPlaybackExpired\(current\.data\)[\s\S]*\? \{ status: "unavailable" \}/);
  assert.match(source, /document\.addEventListener\("visibilitychange", refreshVisiblePlayback\)/);
  assert.match(source, /current\.status === "ready" && isPlaybackRefreshDue\(current\.data\)/);
  assert.match(source, /void requestPlaybackSilently\(\)/);
  assert.match(source, /const hasPlayer = playback\.status === "ready"/);
  assert.doesNotMatch(source, /readonly status: "expired"/);
});

test("wires a shared idle coordinator to observable Kinescope and page events", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /type KinescopeEventName = "Ended" \| "Pause" \| "Playing" \| "Ready"/);
  assert.match(source, /player\.on\(player\.Events\.Pause, handleStoppedPlayback\)/);
  assert.match(source, /player\.on\(player\.Events\.Ended, handleStoppedPlayback\)/);
  assert.match(source, /player\.on\(player\.Events\.Playing, handlePlayingPlayback\)/);
  assert.match(source, /idleCoordinator\.markStopped\(baseElementId\)/);
  assert.match(source, /idleCoordinator\.markPlaying\(baseElementId\)/);
  assert.match(source, /bindStoppedVideoIdleEvents\(idleCoordinator\)/);
  assert.match(source, /logoutClientAccountAfterIdleTimeout\(normalizedApiBaseUrl\)/);
  assert.doesNotMatch(source, /logoutClientAccount\(normalizedApiBaseUrl\)/);
});

test("constructs the idle coordinator without browser globals during server rendering", () => {
  assert.doesNotThrow(
    () =>
      new coordinator.StoppedVideoIdleCoordinator({
        onIdleTimeout: async () => undefined,
      }),
  );
});

test("coordinates one stopped-video deadline, cleanup, logout, and redirect", async () => {
  const clock = createFakeClock();
  const cleanedPlayers: string[] = [];
  const redirects: string[] = [];
  let logoutCalls = 0;
  const idleCoordinator = new coordinator.StoppedVideoIdleCoordinator({
    ...clock,
    onIdleTimeout: () =>
      coordinator.finishStoppedVideoIdleLogout({
        location: {
          hash: "#player",
          pathname: "/lk/subject/course/lesson",
          replace: (url) => redirects.push(url),
          search: "?tab=video",
        },
        logout: async () => {
          logoutCalls += 1;
        },
      }),
  });
  idleCoordinator.registerPlayer("first", () => cleanedPlayers.push("first"));
  idleCoordinator.registerPlayer("second", () => cleanedPlayers.push("second"));

  idleCoordinator.markStopped("first");
  await clock.advanceBy(30 * 60 * 1000);
  idleCoordinator.resetStoppedIdle();
  await clock.advanceBy(30 * 60 * 1000);

  assert.equal(logoutCalls, 0);
  await clock.advanceBy(30 * 60 * 1000);

  assert.deepEqual(cleanedPlayers, ["first", "second"]);
  assert.equal(logoutCalls, 1);
  assert.deepEqual(redirects, ["/auth?mode=login&next=%2Flk%2Fsubject%2Fcourse%2Flesson%3Ftab%3Dvideo%23player"]);
  await Promise.all([
    idleCoordinator.checkStoppedIdleByWallClock(),
    idleCoordinator.checkStoppedIdleByWallClock(),
  ]);
  assert.equal(logoutCalls, 1);
});

test("checks wall-clock timeout on wake and prevents a paused player from expiring while another plays", async () => {
  const clock = createFakeClock();
  const cleanedPlayers: string[] = [];
  let logoutCalls = 0;
  const idleCoordinator = new coordinator.StoppedVideoIdleCoordinator({
    ...clock,
    onIdleTimeout: async () => {
      logoutCalls += 1;
    },
  });
  idleCoordinator.registerPlayer("paused", () => cleanedPlayers.push("paused"));
  idleCoordinator.registerPlayer("playing", () => cleanedPlayers.push("playing"));

  idleCoordinator.markStopped("paused");
  idleCoordinator.markPlaying("playing");
  await clock.advanceBy(coordinator.STOPPED_VIDEO_IDLE_LIMIT_MS + 1);
  await idleCoordinator.checkStoppedIdleByWallClock();

  assert.equal(logoutCalls, 0);
  assert.deepEqual(cleanedPlayers, []);

  idleCoordinator.markStopped("playing");
  clock.advanceWithoutRunningTimers(coordinator.STOPPED_VIDEO_IDLE_LIMIT_MS + 1);
  await idleCoordinator.checkStoppedIdleByWallClock();

  assert.equal(logoutCalls, 1);
  assert.deepEqual(cleanedPlayers, ["paused", "playing"]);
});

test("forwards page activity and wake events without retaining listeners or timers after cleanup", async () => {
  const clock = createFakeClock();
  const pageEvents = createFakeEventTarget();
  const documentEvents = createFakeEventTarget();
  let logoutCalls = 0;
  let resetStoppedIdleCalls = 0;
  let wallClockCheckCalls = 0;
  const idleCoordinator = new coordinator.StoppedVideoIdleCoordinator({
    ...clock,
    onIdleTimeout: async () => {
      logoutCalls += 1;
    },
  });
  idleCoordinator.registerPlayer("paused", () => undefined);
  idleCoordinator.markStopped("paused");
  const resetStoppedIdle = idleCoordinator.resetStoppedIdle.bind(idleCoordinator);
  idleCoordinator.resetStoppedIdle = (): void => {
    resetStoppedIdleCalls += 1;
    resetStoppedIdle();
  };
  const checkStoppedIdleByWallClock = idleCoordinator.checkStoppedIdleByWallClock.bind(idleCoordinator);
  idleCoordinator.checkStoppedIdleByWallClock = (): Promise<void> | null => {
    wallClockCheckCalls += 1;
    return checkStoppedIdleByWallClock();
  };
  const unbind = coordinator.bindStoppedVideoIdleEvents(idleCoordinator, pageEvents.target, documentEvents.target);

  for (const activityEvent of ["pointermove", "click", "keydown", "touchstart"]) {
    clock.advanceWithoutRunningTimers(1);
    pageEvents.dispatch(activityEvent);
  }
  assert.equal(resetStoppedIdleCalls, 4);

  documentEvents.dispatch("visibilitychange");
  assert.equal(wallClockCheckCalls, 1);
  assert.equal(logoutCalls, 0);

  clock.advanceWithoutRunningTimers(coordinator.STOPPED_VIDEO_IDLE_LIMIT_MS + 1);
  pageEvents.dispatch("focus");
  await Promise.resolve();
  assert.equal(wallClockCheckCalls, 2);
  assert.equal(logoutCalls, 1);
  unbind();
  for (const activityEvent of ["pointermove", "click", "keydown", "touchstart", "focus"]) {
    assert.equal(pageEvents.listenerCount(activityEvent), 0);
  }
  assert.equal(documentEvents.listenerCount("visibilitychange"), 0);

  const secondCoordinator = new coordinator.StoppedVideoIdleCoordinator({
    ...clock,
    onIdleTimeout: async () => {
      logoutCalls += 1;
    },
  });
  secondCoordinator.registerPlayer("paused", () => undefined);
  secondCoordinator.markStopped("paused");
  let secondResetStoppedIdleCalls = 0;
  let secondWallClockCheckCalls = 0;
  const secondResetStoppedIdle = secondCoordinator.resetStoppedIdle.bind(secondCoordinator);
  secondCoordinator.resetStoppedIdle = (): void => {
    secondResetStoppedIdleCalls += 1;
    secondResetStoppedIdle();
  };
  const secondCheckStoppedIdleByWallClock = secondCoordinator.checkStoppedIdleByWallClock.bind(secondCoordinator);
  secondCoordinator.checkStoppedIdleByWallClock = (): Promise<void> | null => {
    secondWallClockCheckCalls += 1;
    return secondCheckStoppedIdleByWallClock();
  };
  const secondUnbind = coordinator.bindStoppedVideoIdleEvents(secondCoordinator, pageEvents.target, documentEvents.target);

  assert.equal(clock.timerCount, 1);
  secondUnbind();
  for (const activityEvent of ["pointermove", "click", "keydown", "touchstart"]) {
    pageEvents.dispatch(activityEvent);
  }
  pageEvents.dispatch("focus");
  documentEvents.dispatch("visibilitychange");
  await Promise.resolve();
  assert.equal(secondResetStoppedIdleCalls, 0);
  assert.equal(secondWallClockCheckCalls, 0);
  assert.equal(logoutCalls, 1);
  assert.equal(clock.timerCount, 1);
  idleCoordinator.dispose();
  secondCoordinator.dispose();
  assert.equal(clock.timerCount, 0);
});

test("updates stopped idle activity without churning an active timeout", async () => {
  const clock = createFakeClock();
  let logoutCalls = 0;
  const idleCoordinator = new coordinator.StoppedVideoIdleCoordinator({
    ...clock,
    onIdleTimeout: async () => {
      logoutCalls += 1;
    },
  });
  idleCoordinator.registerPlayer("paused", () => undefined);
  idleCoordinator.markStopped("paused");

  for (let activity = 0; activity < 20; activity += 1) {
    clock.advanceWithoutRunningTimers(1);
    idleCoordinator.resetStoppedIdle();
  }

  assert.equal(clock.setTimeoutCalls, 1);
  assert.equal(clock.clearTimeoutCalls, 0);

  await clock.advanceBy(coordinator.STOPPED_VIDEO_IDLE_LIMIT_MS - 20);
  assert.equal(logoutCalls, 0);
  assert.equal(clock.setTimeoutCalls, 2);
  await clock.advanceBy(20);
  assert.equal(logoutCalls, 1);
});

test("maps trusted-browser playback denials to account recovery without changing other errors", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /response\.status === 403 \? trustedBrowserProtectedErrorCode\(payload\) : null/);
  assert.match(source, /return \{ status: "trusted_browser_recovery", code: trustedBrowserCode \}/);
  assert.match(source, /TRUSTED_BROWSER_REQUIRED/);
  assert.match(source, /TRUSTED_BROWSER_SUPPORT_REQUIRED/);
  assert.match(source, /TRUSTED_BROWSER_RISK_LOCKED/);
  assert.match(source, /href="\/lk#trusted-browsers"/);
  assert.match(source, /data-ui="lk\.learning\.lessonVideo\.trustedBrowserRecovery"/);
  assert.match(source, /return parsePlaybackResponse\(response\.status, payload\)/);
  assert.match(source, /playback\.status === "unavailable"/);
  assert.match(source, /Повторить попытку/);
});
