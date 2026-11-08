import Link from "next/link";
import type { HomeAuthor, PublicArticleDetail } from "@/lib/articlesApi";
import { articleDate, articleListPath } from "@/lib/articleSeo";
import { articleIntroduction } from "@/lib/articlePresentation";
import ArticleBody from "./ArticleBody";
import ArticleImage from "./ArticleImage";
import { SurfaceCard } from "./SurfaceCard";
import { CornerBadge } from "./CornerBadge";
import ArticleRelatedList, { type ArticleRelatedItem } from "./ArticleRelatedList";

interface Props {
  readonly article: PublicArticleDetail;
  readonly authorProfile: HomeAuthor | null;
  readonly sidebarItems: ReadonlyArray<ArticleRelatedItem>;
}

export default function ArticleShowcase({ article, authorProfile, sidebarItems }: Props) {
  const author = article.author;
  const introductoryText = articleIntroduction(article);

  const introduction = <header className="flex flex-col gap-4">
    <div className="flex flex-col gap-2">
      <h1 className="ui-section-title wrap-anywhere">{article.title}</h1>
      <div className="ui-meta-text ui-meta-text--article flex flex-wrap items-center gap-3">
        {article.publishedAt ? <time dateTime={article.publishedAt}>{articleDate(article.publishedAt)}</time> : null}
      </div>
    </div>
    {introductoryText ? <p className="ui-prose" data-ui="articles.showcase.introduction">{introductoryText}</p> : null}
    {article.cover ? <figure className="m-0 aspect-video overflow-hidden rounded-[var(--radius-surface)] [&>picture]:block [&>picture]:h-full" data-ui="articles.showcase.cover">
      <ArticleImage src={article.cover.url} variants={article.cover.variants} alt={article.cover.alt} width={article.cover.width ?? 1600} height={article.cover.height ?? 900} sizes="(min-width: 1280px) 900px, (min-width: 1024px) 66vw, 100vw" className="size-full object-contain" priority />
    </figure> : null}
  </header>;

  return <div className="grid items-start gap-10 lg:grid-cols-[minmax(0,1fr)_18rem] lg:gap-12" data-ui="articles.showcase">
    <article className="flex min-w-0 flex-col gap-8" data-ui="articles.showcase.article">
      {introduction}
      {article.bodyFormat === "html" ? <ArticleBody html={article.body} /> : <div className="ui-prose" data-ui="articles.body">{article.body.split(/\n{2,}/u).filter(Boolean).map((text, index) => <p key={index}>{text}</p>)}</div>}
      {article.attachments.length ? <section aria-labelledby="attachments-title">
        <h2 id="attachments-title" className="ui-card-title">Материалы к статье</h2>
        <ul className="mt-3 list-disc pl-5">{article.attachments.map((file) => <li key={file.url}><a href={file.url} className="underline">{file.name}</a></li>)}</ul>
      </section> : null}
    </article>

    <aside className="articles-detail__sidebar flex min-w-0 flex-col gap-6 lg:self-stretch lg:[contain:size]" aria-label="Автор и похожие статьи" data-ui="articles.showcase.sidebar">
      {author ? <SurfaceCard className="ui-surface-card--badged ui-surface-card--author min-w-0 shrink-0" aria-labelledby="article-author-title" data-ui="articles.showcase.author">
        <CornerBadge as="h2" id="article-author-title" data-ui="articles.showcase.author.badge">Об авторе</CornerBadge>
        <div className="flex w-full min-w-0 flex-col items-start gap-3">
          {author.avatarUrl ? <ArticleImage src={author.avatarUrl} variants={author.avatarVariants} alt="" width={48} height={48} sizes="48px" className="size-12 shrink-0 rounded-full object-cover" /> : null}
          <div className="flex w-full min-w-0 flex-col gap-1">
            <p className="ui-card-text ui-card-text--compact"><strong>{author.name}</strong></p>
            {authorProfile ? <p className="m-0 text-xs font-medium leading-4 text-(--color-text-body) hyphens-none">{authorProfile.homeRoleText}</p> : null}
          </div>
        </div>
      </SurfaceCard> : null}

      {sidebarItems.length ? <SurfaceCard className="ui-surface-card--badged min-w-0 shrink-0" aria-labelledby="related-title" data-ui="articles.related">
        <CornerBadge as="h2" id="related-title" data-ui="articles.showcase.related.badge">Похожие статьи</CornerBadge>
        <ArticleRelatedList items={sidebarItems} />
        <Link href={articleListPath(article.subject ? { subject: article.subject.slug } : {})} className="link-text self-end text-sm lg:mt-auto" data-ui="articles.showcase.related.all">Читать все статьи</Link>
      </SurfaceCard> : null}
    </aside>
  </div>;
}
