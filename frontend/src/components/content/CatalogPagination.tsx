"use client";

import { useRef, type KeyboardEvent } from "react";
import Link from "next/link";
import { ButtonRouteLink } from "@/components/button/Button";

type CatalogPaginationProps = {
  readonly currentPage: number;
  readonly totalPages: number;
  readonly total: number;
  readonly perPage: number;
  readonly previousHref: string;
  readonly nextHref: string;
  readonly pageSizes: ReadonlyArray<{ readonly value: number; readonly href: string }>;
  readonly label: string;
  readonly pageSizeLabel: string;
  readonly dataUi: string;
};

export default function CatalogPagination({ currentPage, totalPages, total, perPage, previousHref, nextHref, pageSizes, label, pageSizeLabel, dataUi }: CatalogPaginationProps) {
  const sizeMenuRef = useRef<HTMLDetailsElement>(null);
  function navigateSizeMenu(event: KeyboardEvent<HTMLDetailsElement>) {
    const menu = sizeMenuRef.current;
    if (!menu) return;
    if (event.key === "Escape" && menu.open) {
      event.preventDefault();
      menu.open = false;
      menu.querySelector("summary")?.focus();
      return;
    }
    if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
    event.preventDefault();
    menu.open = true;
    const choices = Array.from(menu.querySelectorAll<HTMLAnchorElement>("a"));
    const current = choices.indexOf(event.target as HTMLAnchorElement);
    const next = event.key === "Home" ? 0 : event.key === "End" ? choices.length - 1
      : current < 0 ? (event.key === "ArrowUp" ? choices.length - 1 : 0)
      : event.key === "ArrowDown" ? (current + 1) % choices.length : (current - 1 + choices.length) % choices.length;
    choices[next]?.focus();
  }
  if (!total) return null;

  return <nav aria-label={label} data-ui={dataUi} className="flex flex-wrap items-center justify-center gap-x-8 gap-y-3 border-t border-[var(--color-border-subtle)] pt-4 [--button-disabled-bg:transparent] [--button-disabled-border:transparent]">
    <div className="flex items-center gap-3">
      <ButtonRouteLink href={previousHref} disabled={currentPage <= 1} variant="ghost" size="sm" iconOnly fullWidth={false}
        aria-label="Предыдущая страница" rel="prev" data-ui={`${dataUi}.previous`}>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6" /></svg>
      </ButtonRouteLink>
      <p className="m-0 whitespace-nowrap text-sm tabular-nums ui-text-secondary" aria-live="polite">Страница {currentPage} из {totalPages}</p>
      <ButtonRouteLink href={nextHref} disabled={currentPage >= totalPages} variant="ghost" size="sm" iconOnly fullWidth={false}
        aria-label="Следующая страница" rel="next" data-ui={`${dataUi}.next`}>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
      </ButtonRouteLink>
    </div>
    <details ref={sizeMenuRef} className="catalog-pagination__size relative" onKeyDown={navigateSizeMenu}
      onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) event.currentTarget.open = false; }}>
      <summary aria-label={`${pageSizeLabel}: ${perPage}`} data-ui={`${dataUi}.size`}
        className="ui-input flex h-8 cursor-pointer list-none items-center gap-2 rounded-[var(--radius-inner)] border px-3 text-xs focus-visible:outline-none">
        {perPage} на странице
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
      </summary>
      <ul aria-label={pageSizeLabel} className="absolute end-0 top-full z-10 mt-2 w-full list-none rounded-[var(--radius-inner)] border border-[var(--color-border-control)] bg-[var(--color-surface)] p-1 shadow-[var(--shadow-learning-card)]">
        {pageSizes.map((size) => <li key={size.value}>
          <Link href={size.href} aria-current={size.value === perPage ? "page" : undefined}
            className={`block whitespace-nowrap rounded-[var(--radius-inner)] px-3 py-2 text-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand-deep)] ${size.value === perPage ? "ui-text-primary bg-[var(--color-brand-accent-lime)]" : "ui-text-secondary hover:bg-[var(--color-surface-raised)] focus-visible:bg-[var(--color-surface-raised)]"}`}
            onClick={() => { if (sizeMenuRef.current) sizeMenuRef.current.open = false; }}>
            {size.value} на странице
          </Link>
        </li>)}
      </ul>
    </details>
  </nav>;
}
