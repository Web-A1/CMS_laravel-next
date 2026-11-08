export function distinctCourseShortTitle(title: string, shortTitle: string | null): string | null {
  const normalizedTitle = title.trim();
  const normalizedShortTitle = shortTitle?.trim();

  if (!normalizedShortTitle) {
    return null;
  }

  return normalizedShortTitle.localeCompare(normalizedTitle, "ru", { sensitivity: "base" }) === 0
    ? null
    : normalizedShortTitle;
}
