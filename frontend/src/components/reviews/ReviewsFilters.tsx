"use client";

import Link from "next/link";
import type { MouseEvent } from "react";
import { useEffect } from "react";

import PillStrip from "@/components/content/PillStrip";
import type { ReviewSubjectFacet } from "@/lib/reviewsApi";
import type { ReviewSubjectOption } from "./reviewSubjectOptions";
import { reviewSubjectOptions } from "./reviewSubjectOptions";

type ReviewsFiltersProps = {
  readonly subjects: ReadonlyArray<ReviewSubjectFacet>;
  readonly selectedSubject?: string;
  readonly search?: string;
  readonly perPage?: number;
  readonly totalPublished: number;
};

const REVIEWS_FILTER_SCROLL_KEY = "cms.reviews.filterScrollY";

export default function ReviewsFilters({
  subjects,
  selectedSubject,
  search,
  perPage,
  totalPublished,
}: ReviewsFiltersProps) {
  const activeSubject = selectedSubject ?? "";

  useEffect(() => {
    const savedScrollY = window.sessionStorage.getItem(REVIEWS_FILTER_SCROLL_KEY);
    if (savedScrollY === null) {
      return;
    }

    window.sessionStorage.removeItem(REVIEWS_FILTER_SCROLL_KEY);
    const nextScrollY = Number(savedScrollY);
    if (!Number.isFinite(nextScrollY)) {
      return;
    }

    let restoreFrame = 0;
    const layoutFrame = window.requestAnimationFrame(() => {
      restoreFrame = window.requestAnimationFrame(() => {
        const maxScrollY = Math.max(0, document.documentElement.scrollHeight - window.innerHeight);
        window.scrollTo(0, Math.min(nextScrollY, maxScrollY));
      });
    });

    return () => {
      window.cancelAnimationFrame(layoutFrame);
      window.cancelAnimationFrame(restoreFrame);
    };
  }, [activeSubject]);

  const rememberScrollPosition = (event: MouseEvent<HTMLAnchorElement>) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }

    window.sessionStorage.setItem(REVIEWS_FILTER_SCROLL_KEY, String(window.scrollY));
  };

  const subjectOptions: ReadonlyArray<ReviewSubjectOption> = reviewSubjectOptions(subjects, totalPublished, search, perPage);

  return (
    <section className="reviews-filters" data-ui="reviews.filters" aria-label="Фильтры отзывов">
      <PillStrip className="reviews-filters__pills" ariaLabel="Предметы отзывов">
        {subjectOptions.map((subject) => {
          const isActive = subject.slug === activeSubject;

          return (
            <Link
              key={subject.slug || "all"}
              className="ui-pill-control reviews-filters__pill"
              href={subject.href}
              scroll={false}
              onClick={rememberScrollPosition}
              aria-current={isActive ? "page" : undefined}
              data-active={isActive ? "true" : undefined}
              data-ui={subject.slug ? `reviews.filters.subject.${subject.slug}` : "reviews.filters.subject.all"}
            >
              <span className="ui-pill-control__label">{subject.title}</span>
              <span className="ui-pill-control__count">{subject.reviewsCount}</span>
            </Link>
          );
        })}
      </PillStrip>

    </section>
  );
}
