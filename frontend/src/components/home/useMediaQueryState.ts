import { useCallback, useSyncExternalStore } from "react";

type MediaQueryState = "unknown" | "matches" | "not-matches";

function getServerSnapshot(): MediaQueryState {
  return "unknown";
}

export function useMediaQueryState(query: string): MediaQueryState {
  const subscribe = useCallback(
    (onStoreChange: () => void) => {
      if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
        return () => undefined;
      }

      const mediaQuery = window.matchMedia(query);
      mediaQuery.addEventListener("change", onStoreChange);
      return () => mediaQuery.removeEventListener("change", onStoreChange);
    },
    [query],
  );
  const getSnapshot = useCallback((): MediaQueryState => {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
      return "unknown";
    }

    return window.matchMedia(query).matches ? "matches" : "not-matches";
  }, [query]);

  return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
