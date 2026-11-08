import { CLIENT_ACCOUNT_CLEARED_EVENT } from "@/lib/clientAccount";

export type ProtectedDiagramAsset = {
  readonly assetPath: string;
  readonly width: number;
  readonly height: number;
};

export type ProtectedDiagramImageHandle = {
  readonly key: string;
  readonly generation: string;
  readonly objectUrl: string;
};

type RegistryEntry = {
  readonly controller: AbortController;
  promise: Promise<ProtectedDiagramImageHandle>;
  blob?: Blob;
  objectUrl?: string;
};

const MAX_PROTECTED_DIAGRAM_ENTRIES = 9;
let protectedDiagramRegistrySequence = 0;

type IdleWindow = Window & typeof globalThis & {
  requestIdleCallback?: (callback: IdleRequestCallback) => number;
  cancelIdleCallback?: (handle: number) => void;
};

export type ProtectedDiagramRegistryDependencies = {
  readonly fetchAsset?: typeof fetch;
  readonly createObjectURL?: (blob: Blob) => string;
  readonly revokeObjectURL?: (url: string) => void;
  readonly eventTarget?: Pick<EventTarget, "addEventListener" | "removeEventListener"> | null;
  readonly scheduleIdle?: (callback: () => void) => number;
  readonly cancelIdle?: (handle: number) => void;
};

export type ProtectedDiagramRegistry = {
  readonly load: (asset: ProtectedDiagramAsset) => Promise<ProtectedDiagramImageHandle>;
  readonly retry: (asset: ProtectedDiagramAsset) => Promise<ProtectedDiagramImageHandle>;
  readonly preloadAround: (
    current: ProtectedDiagramAsset,
    assets: ReadonlyArray<ProtectedDiagramAsset>,
  ) => void;
  readonly getSnapshot: () => string;
  readonly subscribe: (listener: () => void) => () => void;
  readonly clear: () => void;
  readonly dispose: () => void;
};

function browserIdleScheduler(): Pick<ProtectedDiagramRegistryDependencies, "scheduleIdle" | "cancelIdle"> {
  if (typeof window === "undefined") {
    return {
      scheduleIdle: (callback) => setTimeout(callback, 0) as unknown as number,
      cancelIdle: (handle) => clearTimeout(handle),
    };
  }

  const idleWindow = window as IdleWindow;
  return {
    scheduleIdle: idleWindow.requestIdleCallback
      ? (callback) => idleWindow.requestIdleCallback?.(() => callback()) ?? 0
      : (callback) => window.setTimeout(callback, 0),
    cancelIdle: idleWindow.cancelIdleCallback
      ? (handle) => idleWindow.cancelIdleCallback?.(handle)
      : (handle) => window.clearTimeout(handle),
  };
}

export function createProtectedDiagramRegistry({
  apiBaseUrl,
  attemptId,
  contentVersion,
  onAccessDenied,
  dependencies = {},
}: {
  readonly apiBaseUrl: string;
  readonly attemptId: number;
  readonly contentVersion: number;
  readonly onAccessDenied?: () => void;
  readonly dependencies?: ProtectedDiagramRegistryDependencies;
}): ProtectedDiagramRegistry {
  const idle = browserIdleScheduler();
  const fetchAsset = dependencies.fetchAsset ?? fetch;
  const createObjectURL = dependencies.createObjectURL ?? URL.createObjectURL.bind(URL);
  const revokeObjectURL = dependencies.revokeObjectURL ?? URL.revokeObjectURL.bind(URL);
  const eventTarget = dependencies.eventTarget ?? (typeof window === "undefined" ? null : window);
  const scheduleIdle = dependencies.scheduleIdle ?? idle.scheduleIdle;
  const cancelIdle = dependencies.cancelIdle ?? idle.cancelIdle;
  const normalizedApiBaseUrl = apiBaseUrl.replace(/\/+$/, "");
  const registryInstanceId = ++protectedDiagramRegistrySequence;
  const entries = new Map<string, RegistryEntry>();
  const idleHandles = new Set<number>();
  const listeners = new Set<() => void>();
  let authGeneration = 0;
  let preloadGeneration = 0;
  let invalidated = false;
  let disposed = false;

  const assetKey = (asset: ProtectedDiagramAsset): string => JSON.stringify([
    authGeneration,
    attemptId,
    contentVersion,
    asset.assetPath,
    asset.width,
    asset.height,
  ]);
  const registryGeneration = (): string => JSON.stringify([
    registryInstanceId,
    authGeneration,
    attemptId,
    contentVersion,
  ]);

  const revokeEntry = (key: string): void => {
    const entry = entries.get(key);
    if (entry === undefined) {
      return;
    }
    entries.delete(key);
    entry.controller.abort();
    if (entry.objectUrl !== undefined) {
      revokeObjectURL(entry.objectUrl);
    }
  };

  const cancelPreloads = (): void => {
    preloadGeneration++;
    for (const handle of idleHandles) {
      cancelIdle?.(handle);
    }
    idleHandles.clear();
  };

  const clear = (): void => {
    if (invalidated) {
      return;
    }
    invalidated = true;
    authGeneration++;
    cancelPreloads();
    for (const listener of listeners) {
      listener();
    }
    for (const key of [...entries.keys()]) {
      revokeEntry(key);
    }
  };

  const load = (asset: ProtectedDiagramAsset): Promise<ProtectedDiagramImageHandle> => {
    if (disposed) {
      return Promise.reject(new Error("Protected diagram registry is disposed."));
    }
    if (invalidated) {
      return Promise.reject(new Error("Protected diagram registry is invalidated."));
    }
    const key = assetKey(asset);
    const existing = entries.get(key);
    if (existing !== undefined) {
      return existing.promise;
    }
    while (entries.size >= MAX_PROTECTED_DIAGRAM_ENTRIES) {
      const oldestKey = entries.keys().next().value;
      if (typeof oldestKey !== "string") {
        break;
      }
      revokeEntry(oldestKey);
    }

    const controller = new AbortController();
    const entry: RegistryEntry = {
      controller,
      promise: Promise.resolve({ key, generation: registryGeneration(), objectUrl: "" }),
    };
    entry.promise = fetchAsset(`${normalizedApiBaseUrl}${asset.assetPath}`, {
      method: "GET",
      credentials: "include",
      cache: "no-store",
      signal: controller.signal,
      headers: { Accept: "image/*" },
    }).then(async (response) => {
      if (response.status === 401 || response.status === 403) {
        clear();
        onAccessDenied?.();
        throw new Error(`Protected diagram access denied (${response.status}).`);
      }
      if (!response.ok) {
        throw new Error(`Protected diagram request failed (${response.status}).`);
      }

      const blob = await response.blob();
      if (disposed || entries.get(key) !== entry) {
        throw new DOMException("Protected diagram request was superseded.", "AbortError");
      }
      const objectUrl = createObjectURL(blob);
      entry.blob = blob;
      entry.objectUrl = objectUrl;

      return { key, generation: registryGeneration(), objectUrl };
    }).catch((error: unknown) => {
      if (entries.get(key) === entry && entry.objectUrl === undefined) {
        entries.delete(key);
      }
      throw error;
    });
    entries.set(key, entry);

    return entry.promise;
  };

  const retry = (asset: ProtectedDiagramAsset): Promise<ProtectedDiagramImageHandle> => {
    revokeEntry(assetKey(asset));
    return load(asset);
  };

  const scheduleSequentialIdle = (
    assets: ReadonlyArray<ProtectedDiagramAsset>,
    generation: number,
    index = 0,
  ): void => {
    if (disposed || generation !== preloadGeneration || index >= assets.length) {
      return;
    }
    const scheduled = { fired: false, handle: undefined as number | undefined };
    const handle = scheduleIdle?.(() => {
      scheduled.fired = true;
      if (scheduled.handle !== undefined) {
        idleHandles.delete(scheduled.handle);
      }
      if (disposed || generation !== preloadGeneration) {
        return;
      }
      void load(assets[index]).catch(() => undefined).finally(() => {
        scheduleSequentialIdle(assets, generation, index + 1);
      });
    });
    scheduled.handle = handle;
    if (handle !== undefined && !scheduled.fired) {
      idleHandles.add(handle);
    }
  };

  const preloadAround = (
    current: ProtectedDiagramAsset,
    assets: ReadonlyArray<ProtectedDiagramAsset>,
  ): void => {
    cancelPreloads();
    const generation = preloadGeneration;
    const currentIndex = assets.findIndex((asset) => assetKey(asset) === assetKey(current));
    const adjacentIndexes = [currentIndex - 1, currentIndex + 1]
      .filter((index) => index >= 0 && index < assets.length);
    const adjacent = adjacentIndexes.map((index) => assets[index]);
    const adjacentIndexSet = new Set([currentIndex, ...adjacentIndexes]);
    const remaining = assets.filter((_asset, index) => !adjacentIndexSet.has(index));

    void load(current).then(async () => {
      if (disposed || generation !== preloadGeneration) {
        return;
      }
      await Promise.all(adjacent.map((asset) => load(asset).catch(() => undefined)));
      scheduleSequentialIdle(remaining, generation);
    }).catch(() => undefined);
  };

  const handleAccountClear = (): void => clear();
  eventTarget?.addEventListener(CLIENT_ACCOUNT_CLEARED_EVENT, handleAccountClear);

  return {
    load,
    retry,
    preloadAround,
    getSnapshot: () => invalidated
      ? "invalid:" + registryGeneration()
      : registryGeneration(),
    subscribe: (listener) => {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    clear,
    dispose: () => {
      if (disposed) {
        return;
      }
      disposed = true;
      eventTarget?.removeEventListener(CLIENT_ACCOUNT_CLEARED_EVENT, handleAccountClear);
      clear();
      listeners.clear();
    },
  };
}
