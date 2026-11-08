export const ARTICLE_PAGE_SIZES = [10, 25, 50] as const;
export const REVIEW_PAGE_SIZES = [10, 25, 50] as const;

export function catalogPageSize(value: unknown, choices: readonly number[], defaultSize: number): number | null {
  if (value === undefined) return defaultSize;
  if (typeof value !== "string") return null;
  return choices.find((size) => String(size) === value) ?? null;
}
