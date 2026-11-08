import { listArticlesResult, type PublicArticleDetail, type PublicArticleSummary } from "@/lib/articlesApi";
import { rethrowNextDynamicServerError } from "@/lib/apiErrors";

export async function articleSidebarItems(article: PublicArticleDetail): Promise<ReadonlyArray<PublicArticleSummary>> {
  const ids = new Set([article.id]);
  const slugs = new Set([article.slug]);
  const items: PublicArticleSummary[] = [];
  const append = (candidates: ReadonlyArray<PublicArticleSummary>) => {
    for (const item of candidates) {
      if (article.subject && item.subject?.slug !== article.subject.slug) continue;
      if (ids.has(item.id) || slugs.has(item.slug)) continue;
      ids.add(item.id);
      slugs.add(item.slug);
      items.push(item);
    }
  };
  append(article.related);
  if (!article.subject) return items;
  const fallback = [...items];
  try {
    let page = 1;
    let lastPage = 1;
    do {
      const result = await listArticlesResult({ subject: article.subject.slug, limit: 50, page });
      append(result.data);
      lastPage = result.meta.lastPage;
      page += 1;
    } while (page <= lastPage);
    return items;
  } catch (error) {
    rethrowNextDynamicServerError(error);
    console.warn("Unable to load additional article sidebar items", error);
    return fallback;
  }
}
