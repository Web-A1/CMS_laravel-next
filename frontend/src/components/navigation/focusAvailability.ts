export type HeaderVisibilityInput = {
  readonly atTop: boolean;
  readonly currentVisible: boolean;
  readonly focusInsideHeader: boolean;
  readonly menuOpen: boolean;
  readonly scrollDirection: "down" | "none" | "up";
};

export function resolveHeaderVisible(input: HeaderVisibilityInput): boolean {
  if (input.atTop || input.menuOpen || input.focusInsideHeader) return true;
  if (input.scrollDirection === "down") return false;
  if (input.scrollDirection === "up") return true;
  return input.currentVisible;
}

export function hiddenControlTabIndex(visible: boolean): 0 | -1 {
  return visible ? 0 : -1;
}

export function headerIsInert(visible: boolean, canHideInViewport = true): boolean {
  return !visible && canHideInViewport;
}
