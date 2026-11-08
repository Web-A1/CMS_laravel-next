import type { Metadata } from "next";
import { isSiteIndexingEnabled } from "@/lib/siteIndexingPolicy";
import { cache } from "react";
import { notFound } from "next/navigation";
import "../../../articles.css";
import { findArticle, findArticleAuthorProfile } from "@/lib/articlesApi";
import { articleBreadcrumbItems, articleDescription, articleSiteUrl, articleStructuredData, safeJsonLd } from "@/lib/articleSeo";
import BreadcrumbTrail from "@/components/breadcrumbs/BreadcrumbTrail";
import ArticleShowcase from "@/components/content/ArticleShowcase";
import { articleSidebarItems } from "@/lib/articleSidebar";

type Props = { readonly params: Promise<{ articleSlug: string }> };
const getArticle = cache(findArticle);

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const article = await getArticle((await params).articleSlug);
  if (!article) notFound();
  const origin = articleSiteUrl();
  const url = `${origin}/articles/${encodeURIComponent(article.slug)}`;
  const title = article.seoTitle || article.title;
  const description = articleDescription(article);
  const images = article.cover ? [{ url: new URL(article.cover.url, origin).href, alt: article.cover.alt }] : [];
  return {
    title, description, alternates: { canonical: url },
    robots: isSiteIndexingEnabled(process.env.SITE_INDEXING_ENABLED, process.env.SITE_URL) ? { index: true, follow: true } : { index: false, follow: false },
    openGraph: { type: "article", title, description, url, images, locale: "ru_RU", siteName: "Christmedschool",
      publishedTime: article.publishedAt ?? undefined, modifiedTime: article.updatedAt ?? undefined,
      authors: article.author ? [article.author.name] : undefined },
    twitter: { card: "summary_large_image", title, description, images: images.map((image) => image.url) },
  };
}

export default async function ArticlePage({ params }: Props) {
  const article = await getArticle((await params).articleSlug);
  if (!article) notFound();
  const [authorProfile, sidebarItems] = await Promise.all([findArticleAuthorProfile(article.author), articleSidebarItems(article)]);
  return <>
    <BreadcrumbTrail items={articleBreadcrumbItems(article)} />
    <main data-ui="articles.detail" className="content-shell page-content page-gutter mx-auto w-full">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(articleStructuredData(article, articleSiteUrl())) }} />
      <ArticleShowcase article={article} authorProfile={authorProfile} sidebarItems={sidebarItems.map(({ id, slug, title, excerpt, description, publishedAt }) => ({ id, slug, title, excerpt, description, publishedAt }))} />
    </main>
  </>;
}
