type HorizontalScrollMetrics = {
  readonly clientWidth: number;
  readonly scrollLeft: number;
  readonly scrollWidth: number;
};

export function keyboardHorizontalScrollTarget(
  key: string,
  { clientWidth, scrollLeft, scrollWidth }: HorizontalScrollMetrics,
): number | null {
  const maximumScrollLeft = Math.max(0, scrollWidth - clientWidth);
  const step = Math.max(160, Math.round(clientWidth * 0.8));

  switch (key) {
    case "ArrowLeft":
      return Math.max(0, scrollLeft - step);
    case "ArrowRight":
      return Math.min(maximumScrollLeft, scrollLeft + step);
    case "Home":
      return 0;
    case "End":
      return maximumScrollLeft;
    default:
      return null;
  }
}
