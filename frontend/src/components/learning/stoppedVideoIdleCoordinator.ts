export const STOPPED_VIDEO_IDLE_LIMIT_MS = 60 * 60 * 1000;

type PlayerState = "playing" | "stopped" | "unknown";

type RegisteredPlayer = {
  readonly cleanup: () => void;
  state: PlayerState;
};

type StoppedVideoIdleCoordinatorDependencies = {
  readonly clearTimeout: (timerId: number) => void;
  readonly now: () => number;
  readonly onIdleTimeout: () => Promise<void>;
  readonly setTimeout: (handler: () => void, delayMs: number) => number;
};

type StoppedVideoIdleLocation = {
  readonly hash: string;
  readonly pathname: string;
  readonly search: string;
  replace(url: string): void;
};

type StoppedVideoIdleLogoutDependencies = {
  readonly location: StoppedVideoIdleLocation;
  readonly logout: () => Promise<void>;
};

type StoppedVideoIdleEventTarget = Pick<EventTarget, "addEventListener" | "removeEventListener">;

function browserClearTimeout(timerId: number): void {
  if (typeof window !== "undefined") {
    window.clearTimeout(timerId);
  }
}

function browserSetTimeout(handler: () => void, delayMs: number): number {
  if (typeof window === "undefined") {
    return 0;
  }

  return window.setTimeout(handler, delayMs);
}

export async function finishStoppedVideoIdleLogout({
  location,
  logout,
}: StoppedVideoIdleLogoutDependencies): Promise<void> {
  const nextPath = `${location.pathname}${location.search}${location.hash}`;
  try {
    await logout();
  } catch {
    // Navigation to login remains mandatory when the local idle limit has elapsed.
  } finally {
    location.replace(`/auth?mode=login&next=${encodeURIComponent(nextPath)}`);
  }
}

export function bindStoppedVideoIdleEvents(
  coordinator: StoppedVideoIdleCoordinator,
  windowTarget: StoppedVideoIdleEventTarget = window,
  documentTarget: StoppedVideoIdleEventTarget = document,
): () => void {
  const activityEvents = ["pointermove", "click", "keydown", "touchstart"] as const;
  const resetStoppedIdleTimer = (): void => coordinator.resetStoppedIdle();
  const checkStoppedIdleByWallClock = (): void => {
    void coordinator.checkStoppedIdleByWallClock();
  };

  for (const eventName of activityEvents) {
    windowTarget.addEventListener(eventName, resetStoppedIdleTimer, { passive: true });
  }
  documentTarget.addEventListener("visibilitychange", checkStoppedIdleByWallClock);
  windowTarget.addEventListener("focus", checkStoppedIdleByWallClock);

  return () => {
    for (const eventName of activityEvents) {
      windowTarget.removeEventListener(eventName, resetStoppedIdleTimer);
    }
    documentTarget.removeEventListener("visibilitychange", checkStoppedIdleByWallClock);
    windowTarget.removeEventListener("focus", checkStoppedIdleByWallClock);
  };
}

export class StoppedVideoIdleCoordinator {
  private readonly clearTimeout: (timerId: number) => void;
  private readonly now: () => number;
  private readonly onIdleTimeout: () => Promise<void>;
  private readonly players = new Map<string, RegisteredPlayer>();
  private readonly setTimeout: (handler: () => void, delayMs: number) => number;
  private idleLogoutPromise: Promise<void> | null = null;
  private stoppedAt: number | null = null;
  private timerId: number | null = null;

  public constructor(dependencies: Partial<StoppedVideoIdleCoordinatorDependencies> & Pick<StoppedVideoIdleCoordinatorDependencies, "onIdleTimeout">) {
    this.clearTimeout = dependencies.clearTimeout ?? browserClearTimeout;
    this.now = dependencies.now ?? Date.now;
    this.onIdleTimeout = dependencies.onIdleTimeout;
    this.setTimeout = dependencies.setTimeout ?? browserSetTimeout;
  }

  public registerPlayer(playerId: string, cleanup: () => void): () => void {
    this.players.set(playerId, { cleanup, state: "unknown" });

    return () => {
      this.players.delete(playerId);
      this.reconcileStoppedIdle();
    };
  }

  public markPlaying(playerId: string): void {
    const player = this.players.get(playerId);
    if (!player || this.idleLogoutPromise) {
      return;
    }

    player.state = "playing";
    this.reconcileStoppedIdle();
  }

  public markStopped(playerId: string): void {
    const player = this.players.get(playerId);
    if (!player || this.idleLogoutPromise) {
      return;
    }

    player.state = "stopped";
    this.reconcileStoppedIdle();
  }

  public resetStoppedIdle(): void {
    if (this.idleLogoutPromise || !this.hasStoppedPlayer() || this.hasPlayingPlayer()) {
      return;
    }

    this.stoppedAt = this.now();
    this.scheduleStoppedIdleCheck();
  }

  public checkStoppedIdleByWallClock(): Promise<void> | null {
    if (this.idleLogoutPromise || this.stoppedAt === null || this.hasPlayingPlayer() || !this.hasStoppedPlayer()) {
      return this.idleLogoutPromise;
    }

    if (this.now() - this.stoppedAt >= STOPPED_VIDEO_IDLE_LIMIT_MS) {
      return this.startIdleLogout();
    }

    this.scheduleStoppedIdleCheck();
    return null;
  }

  public dispose(): void {
    this.clearStoppedIdleTimer();
    this.players.clear();
    this.stoppedAt = null;
  }

  private reconcileStoppedIdle(): void {
    if (this.idleLogoutPromise || this.hasPlayingPlayer() || !this.hasStoppedPlayer()) {
      this.stoppedAt = null;
      this.clearStoppedIdleTimer();
      return;
    }

    if (this.stoppedAt === null) {
      this.stoppedAt = this.now();
    }
    this.scheduleStoppedIdleCheck();
  }

  private hasPlayingPlayer(): boolean {
    return Array.from(this.players.values()).some((player) => player.state === "playing");
  }

  private hasStoppedPlayer(): boolean {
    return Array.from(this.players.values()).some((player) => player.state === "stopped");
  }

  private scheduleStoppedIdleCheck(): void {
    if (this.stoppedAt === null || this.idleLogoutPromise || this.timerId !== null) {
      return;
    }

    const remainingMs = Math.max(0, STOPPED_VIDEO_IDLE_LIMIT_MS - (this.now() - this.stoppedAt));
    this.timerId = this.setTimeout(() => {
      this.timerId = null;
      void this.checkStoppedIdleByWallClock();
    }, remainingMs);
  }

  private clearStoppedIdleTimer(): void {
    if (this.timerId !== null) {
      this.clearTimeout(this.timerId);
      this.timerId = null;
    }
  }

  private startIdleLogout(): Promise<void> {
    if (this.idleLogoutPromise) {
      return this.idleLogoutPromise;
    }

    this.clearStoppedIdleTimer();
    this.idleLogoutPromise = (async () => {
      for (const player of this.players.values()) {
        player.cleanup();
      }
      await this.onIdleTimeout();
    })().catch(() => undefined);

    return this.idleLogoutPromise;
  }
}
