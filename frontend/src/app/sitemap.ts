import type { MetadataRoute } from "next";
import { isSiteIndexingEnabled } from "@/lib/siteIndexingPolicy";
import { articleSubjects, listArticlesResult } from "@/lib/articlesApi";
import { articleListPath, articleSiteUrl } from "@/lib/articleSeo";

export const dynamic = "force-dynamic";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  if (!isSiteIndexingEnabled(process.env.SITE_INDEXING_ENABLED, process.env.SITE_URL)) return [];
  const origin = articleSiteUrl();
  const subjects = await articleSubjects();
  const entries: MetadataRoute.Sitemap = [{ url: `${origin}/` }, { url: `${origin}/articles` },
    ...subjects.filter((subject) => subject.count > 0).map((subject) => ({ url: `${origin}${articleListPath({ subject: subject.slug })}` }))];
  let page = 1;
  let lastPage = 1;
  do {
    const result = await listArticlesResult({ page, limit: 50 });
    lastPage = result.meta.lastPage;
    for (const article of result.data) entries.push({ url: `${origin}/articles/${encodeURIComponent(article.slug)}`, lastModified: article.updatedAt ?? article.publishedAt ?? undefined });
    page++;
  } while (page <= lastPage);
  return entries;
}
