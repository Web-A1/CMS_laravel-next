type StateInput = { disabled?: boolean; loading?: boolean; ariaDisabled?: boolean | "true" | "false" };

export function buttonState({ disabled = false, loading = false, ariaDisabled }: StateInput = {}) {
  const blocked = disabled || loading || ariaDisabled === true || ariaDisabled === "true";
  return { blocked, nativeDisabled: disabled && !loading, ariaDisabled: blocked };
}

export function revealGeometry(width: number, height: number, pointerX: number, pointerY: number) {
  const x = Math.max(0, Math.min(width, pointerX));
  const y = Math.max(0, Math.min(height, pointerY));
  return { x, y, radius: Math.hypot(Math.max(x, width - x), Math.max(y, height - y)) + 2 };
}
