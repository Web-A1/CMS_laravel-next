"use client";

import Link from "next/link";
import { useLayoutEffect, useRef } from "react";
import type { PublicArticleSummary } from "@/lib/articlesApi";
import { articleDate } from "@/lib/articleSeo";

export type ArticleRelatedItem = Pick<PublicArticleSummary, "id" | "slug" | "title" | "excerpt" | "description" | "publishedAt">;

interface Props {
  readonly items: ReadonlyArray<ArticleRelatedItem>;
}

export default function ArticleRelatedList({ items }: Props) {
  const listRef = useRef<HTMLUListElement>(null);

  useLayoutEffect(() => {
    const list = listRef.current;
    const showcase = list?.closest('[data-ui="articles.showcase"]');
    const article = showcase?.querySelector<HTMLElement>('[data-ui="articles.showcase.article"]');
    const card = list?.closest<HTMLElement>('[data-ui="articles.related"]');
    if (!list || !showcase || !article || !card) return;

    const rows = Array.from(list.children).filter((node): node is HTMLLIElement => node instanceof HTMLLIElement);
    const desktop = window.matchMedia("(min-width: 1024px)");
    let frame = 0;
    let disposed = false;
    const measure = () => {
      frame = 0;
      let count = Math.min(3, rows.length);
      if (desktop.matches) {
        // Measure the one real list, then restore visibility before the browser paints.
        for (const row of rows) row.style.display = "";
        const available = article.getBoundingClientRect().bottom - card.getBoundingClientRect().top;
        const gap = Number.parseFloat(getComputedStyle(list).rowGap) || 0;
        let used = card.getBoundingClientRect().height - list.getBoundingClientRect().height;
        count = 0;
        for (const row of rows) {
          const next = used + row.getBoundingClientRect().height + (count ? gap : 0);
          if (next > available) break;
          used = next;
          count += 1;
        }
      }
      rows.forEach((row, index) => { row.style.display = index < count ? "" : "none"; });
    };
    const schedule = () => {
      if (!disposed && !frame) frame = requestAnimationFrame(measure);
    };
    const observer = new ResizeObserver(schedule);
    observer.observe(article);
    const author = showcase.querySelector('[data-ui="articles.showcase.author"]');
    if (author) observer.observe(author);
    let listWidth = list.getBoundingClientRect().width;
    const widthObserver = new ResizeObserver(([entry]) => {
      if (entry && entry.contentRect.width !== listWidth) {
        listWidth = entry.contentRect.width;
        schedule();
      }
    });
    widthObserver.observe(list);
    showcase.addEventListener("load", schedule, true);
    window.addEventListener("resize", schedule);
    document.fonts.addEventListener("loadingdone", schedule);
    void document.fonts.ready.then(schedule);
    measure();

    return () => {
      disposed = true;
      cancelAnimationFrame(frame);
      observer.disconnect();
      widthObserver.disconnect();
      showcase.removeEventListener("load", schedule, true);
      window.removeEventListener("resize", schedule);
      document.fonts.removeEventListener("loadingdone", schedule);
    };
  }, [items]);

  return <ul ref={listRef} className="m-0 flex w-full list-none flex-col gap-5 p-0">
    {items.map((item, index) => <li key={item.id} className="min-w-0" style={{ display: index < 3 ? undefined : "none" }}>
      <Link href={`/articles/${item.slug}`} className="ui-text-link w-full items-start" data-ui={`articles.showcase.related.${item.slug}`}>
        <span className="flex w-full min-w-0 flex-col gap-2 whitespace-normal">
          <span className="flex min-w-0 flex-col gap-1">
            <span className="text-sm font-medium leading-tight text-(--color-text-body) line-clamp-3"><strong>{item.title}</strong></span>
            {item.excerpt || item.description ? <span className="text-xs font-medium leading-4 text-(--color-text-body) hyphens-auto line-clamp-3" data-ui="articles.showcase.related.description">{item.excerpt || item.description}</span> : null}
          </span>
          {item.publishedAt ? <time className="ui-meta-text" dateTime={item.publishedAt}>{articleDate(item.publishedAt)}</time> : null}
        </span>
      </Link>
    </li>)}
  </ul>;
}
