import ArticleImage from "./ArticleImage";
import Link from "next/link";
import type { PublicArticleSummary } from "@/lib/articlesApi";
import { articleDate } from "@/lib/articleSeo";
import { CornerBadge } from "./CornerBadge";
import "./article-card.css";

export default function ArticleCard({ article }: { readonly article: PublicArticleSummary }) {
  return <article className="h-full" data-ui={`articles.card.${article.slug}`}>
    <Link href={`/articles/${article.slug}`} className="ui-surface-card ui-surface-card--editorial article-card h-full text-inherit no-underline">
      <div className="ui-editorial-cover article-card__cover relative aspect-video w-full overflow-hidden rounded-[var(--radius-surface)]">
        {article.cover ? <ArticleImage src={article.cover.url} variants={article.cover.variants} alt={article.cover.alt} width={article.cover.width ?? 600} height={article.cover.height ?? 400} sizes="(min-width: 1216px) 384px, (min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw" className="h-full w-full object-cover" /> : null}
        <CornerBadge withHover>{article.subject?.title ?? "Материал"}</CornerBadge>
      </div>
      <h2 className="ui-card-title line-clamp-3">{article.title}</h2>
      {article.excerpt || article.description ? <p className="ui-card-text line-clamp-3">{article.excerpt || article.description}</p> : null}
      <div className="article-card__footer flex w-full items-center gap-3 pt-2">
        <span className="ui-meta-text min-w-0 truncate">{article.author?.name}</span>
        {article.publishedAt ? <time className="ui-meta-text ml-auto shrink-0 whitespace-nowrap" dateTime={article.publishedAt}>{articleDate(article.publishedAt)}</time> : null}
      </div>
    </Link>
  </article>;
}
