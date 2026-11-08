import type { ArticleFilters, PublicArticleDetail } from "./articlesApi";
import type { BreadcrumbItem } from "../components/breadcrumbs/breadcrumbLabels";

export function articleSiteUrl(): string {
  const configured = process.env.SITE_URL;
  if (!configured && process.env.SITE_INDEXING_ENABLED === "true") throw new Error("SITE_URL is required for indexing");
  const url = new URL(configured ?? "http://localhost:3000");
  if (!["https:", "http:"].includes(url.protocol) || url.username || url.password || url.pathname !== "/" || url.search || url.hash) throw new Error("SITE_URL must be an HTTP(S) origin");
  return url.origin;
}

export function articleListPath(filters: Pick<ArticleFilters, "subject" | "category" | "q"> = {}, page = 1, perPage = 25): string {
  const query = new URLSearchParams();
  if (filters.subject) query.set("subject", filters.subject);
  if (filters.category) query.set("category", filters.category);
  if (filters.q) query.set("q", filters.q);
  if (page > 1) query.set("page", String(page));
  if (perPage !== 25) query.set("per_page", String(perPage));
  return `/articles${query.size ? `?${query}` : ""}`;
}

export function articleDescription(article: PublicArticleDetail): string {
  return article.seoDescription?.trim() || article.excerpt?.trim() || article.description?.trim() || article.title;
}

export function safeJsonLd(value: unknown): string {
  return JSON.stringify(value).replace(/</gu, "\\u003c");
}

export function articleBreadcrumbItems(article: Pick<PublicArticleDetail, "slug" | "title" | "subject">): ReadonlyArray<BreadcrumbItem> {
  return [
    { label: "Главная", href: "/" },
    { label: "Статьи", href: "/articles" },
    ...(article.subject ? [{ label: article.subject.title, href: articleListPath({ subject: article.subject.slug }) }] : []),
    { label: article.title, href: `/articles/${encodeURIComponent(article.slug)}` },
  ];
}

export function articleStructuredData(article: PublicArticleDetail, origin: string) {
  const url = `${origin}/articles/${encodeURIComponent(article.slug)}`;
  const crumbs = articleBreadcrumbItems(article);
  return {
    "@context": "https://schema.org", "@graph": [
      {
        "@type": "Article", "@id": `${url}#article`, url, mainEntityOfPage: url,
        headline: article.title, description: articleDescription(article), inLanguage: "ru",
        ...(article.publishedAt ? { datePublished: article.publishedAt } : {}),
        ...(article.updatedAt ? { dateModified: article.updatedAt } : {}),
        ...(article.author ? { author: { "@type": "Person", name: article.author.name } } : {}),
        ...(article.cover ? { image: [new URL(article.cover.url, origin).href] } : {}),
        publisher: { "@type": "Organization", name: "Christmedschool", url: origin },
      },
      { "@type": "BreadcrumbList", itemListElement: crumbs.map((crumb, index) => ({
        "@type": "ListItem", position: index + 1, name: crumb.label, item: new URL(crumb.href, origin).href,
      })) },
    ],
  };
}

export function articleDate(value: string): string {
  return new Intl.DateTimeFormat("ru-RU", { day: "numeric", month: "long", year: "numeric", timeZone: "Europe/Moscow" }).format(new Date(value));
}
