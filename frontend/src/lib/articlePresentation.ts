import type { PublicArticleDetail } from "./articlesApi";

const entities: Readonly<Record<string, string>> = { amp: "&", lt: "<", gt: ">", quot: '"', apos: "'", nbsp: " " };

function documentText(value: string): string {
  return value
    .replace(/<\/?(?:p|h[1-6]|div|li|tr|td|th|ul|ol|blockquote|br|hr|table|thead|tbody|tfoot|figure|figcaption)\b[^>]*>/giu, " ")
    .replace(/<[^>]*>/gu, "")
    .replace(/&(#x[\da-f]+|#\d+|amp|lt|gt|quot|apos|nbsp);/giu, (entity, name: string) => {
      if (!name.startsWith("#")) return entities[name.toLowerCase()] ?? entity;
      const point = name[1].toLowerCase() === "x" ? Number.parseInt(name.slice(2), 16) : Number.parseInt(name.slice(1), 10);
      return point > 0 && point <= 0x10ffff ? String.fromCodePoint(point) : entity;
    })
    .replace(/[\s\u00a0]+/gu, " ").trim();
}

/** Imported excerpts describe catalog cards; a repeated prefix is already in the body. */
export function articleIntroduction(article: Pick<PublicArticleDetail, "excerpt" | "body" | "bodyFormat">): string | null {
  const excerpt = article.excerpt?.trim();
  if (!excerpt) return null;
  const body = article.bodyFormat === "html" ? documentText(article.body) : article.body.replace(/[\s\u00a0]+/gu, " ").trim();
  return body.startsWith(excerpt.replace(/[\s\u00a0]+/gu, " ")) ? null : excerpt;
}
