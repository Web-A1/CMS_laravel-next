export type HeroPlacement = Readonly<{ x: number; y: number; width: number; shadowX?: number; shadowY?: number }>;
export type HeroPlacements = Readonly<Record<string, HeroPlacement>>;
export const HERO_EDITOR_STORAGE_PREFIX = "cms.hero-non-mobile.v1";

export function boundedHeroValue(field: keyof HeroPlacement, value: number): number {
  return field === "width" ? Math.max(1, Math.min(5000, value)) : Math.max(-10000, Math.min(10000, value));
}

export function adjustHeroPlacement(card: HeroPlacement, action: string, step = 1, target: "card" | "shadow" = "card"): HeroPlacement {
  if (target === "shadow" && action.startsWith("Arrow")) {
    const field = action === "ArrowLeft" || action === "ArrowRight" ? "shadowX" : "shadowY";
    const direction = action === "ArrowLeft" || action === "ArrowUp" ? -1 : 1;
    return { ...card, [field]: boundedHeroValue(field, (card[field] ?? 0) + direction * step) };
  }
  switch (action) {
    case "ArrowLeft": return { ...card, x: boundedHeroValue("x", card.x - step) };
    case "ArrowRight": return { ...card, x: boundedHeroValue("x", card.x + step) };
    case "ArrowUp": return { ...card, y: boundedHeroValue("y", card.y - step) };
    case "ArrowDown": return { ...card, y: boundedHeroValue("y", card.y + step) };
    case "+": return { ...card, width: boundedHeroValue("width", card.width + step) };
    case "-": return { ...card, width: boundedHeroValue("width", card.width - step) };
    default: return card;
  }
}

export function parseHeroDraft(raw: string | null, viewportWidth: number, defaults: HeroPlacements): HeroPlacements | null {
  if (!raw) return null;
  try {
    const data: unknown = JSON.parse(raw);
    if (!data || typeof data !== "object" || !("version" in data) || data.version !== 1 ||
        !("viewport" in data) || !data.viewport || typeof data.viewport !== "object" ||
        !("width" in data.viewport) || data.viewport.width !== viewportWidth || !("cards" in data) ||
        !data.cards || typeof data.cards !== "object") return null;
    const cards: Record<string, HeroPlacement> = {};
    for (const id of Object.keys(defaults)) {
      const card: unknown = Reflect.get(data.cards, id);
      if (!card || typeof card !== "object") return null;
      const { x, y, width } = card as Record<string, unknown>;
      if (typeof x !== "number" || !Number.isFinite(x) || boundedHeroValue("x", x) !== x ||
          typeof y !== "number" || !Number.isFinite(y) || boundedHeroValue("y", y) !== y ||
          typeof width !== "number" || !Number.isFinite(width) || boundedHeroValue("width", width) !== width) return null;
      cards[id] = { x, y, width };
      for (const field of ["shadowX", "shadowY"] as const) {
        const savedValue: unknown = Reflect.get(card, field);
        const value = savedValue === undefined ? defaults[id][field] : savedValue;
        if (value === undefined) continue;
        if (typeof value !== "number" || !Number.isFinite(value) || boundedHeroValue(field, value) !== value) return null;
        cards[id] = { ...cards[id], [field]: value };
      }
    }
    return cards;
  } catch { return null; }
}

export function clampPanelPosition(left: number, top: number, width: number, height: number, viewportWidth: number, viewportHeight: number) {
  return {
    left: Math.max(8, Math.min(left, viewportWidth - width - 8)),
    top: Math.max(8, Math.min(top, viewportHeight - height - 8)),
  };
}
