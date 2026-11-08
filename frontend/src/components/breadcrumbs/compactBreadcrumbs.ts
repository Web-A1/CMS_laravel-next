export type BreadcrumbTrailItem = Readonly<{
  href?: string;
  label: string;
}>;

export type VisibleBreadcrumbEntry<TItem extends BreadcrumbTrailItem> =
  | Readonly<{
      kind: "item";
      item: TItem;
      sourceIndex: number;
    }>
  | Readonly<{
      hiddenItems: ReadonlyArray<TItem>;
      kind: "ellipsis";
    }>;

export function compactBreadcrumbItems<TItem extends BreadcrumbTrailItem>(
  items: ReadonlyArray<TItem>,
  maxVisibleItems = 4,
): ReadonlyArray<VisibleBreadcrumbEntry<TItem>> {
  const visibleLimit = Math.max(3, maxVisibleItems);

  if (items.length <= visibleLimit) {
    return items.map((item, sourceIndex) => ({
      kind: "item",
      item,
      sourceIndex,
    }));
  }

  const firstItem = items[0];
  if (!firstItem) {
    return [];
  }

  const tailCount = visibleLimit - 2;
  const tailStart = items.length - tailCount;
  const hiddenItems = items.slice(1, tailStart);

  return [
    {
      kind: "item",
      item: firstItem,
      sourceIndex: 0,
    },
    {
      hiddenItems,
      kind: "ellipsis",
    },
    ...items.slice(tailStart).map((item, index) => ({
      kind: "item" as const,
      item,
      sourceIndex: tailStart + index,
    })),
  ];
}
