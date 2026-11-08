"use client";

import { useId } from "react";

import Button, { ButtonRouteLink } from "@/components/button/Button";
import { reviewsHref } from "./reviewSubjectOptions";
import { useAdminReviewSearchVisibility } from "./useAdminReviewSearchVisibility";

export default function AdminReviewSearch({ search, subject }: {
  readonly search: string;
  readonly subject?: string;
}) {
  const id = useId();
  const visible = useAdminReviewSearchVisibility();
  if (!visible) return null;

  return (
    <form action="/reviews" method="get" role="search" aria-label="Поиск отзывов" data-ui="reviews.admin-search" className="mb-4 flex flex-wrap items-end gap-2">
      {subject ? <input type="hidden" name="subject" value={subject} /> : null}
      <label htmlFor={id} className="flex min-w-0 flex-1 flex-col gap-1 sm:max-w-lg">
        <span className="text-sm ui-text-secondary">Поиск отзывов</span>
        <input key={search} id={id} name="q" type="search" maxLength={200} defaultValue={search} placeholder="Текст отзыва, ID или имя автора" className="ui-input w-full rounded-lg border px-3 py-2 text-base sm:text-sm" data-ui="reviews.admin-search.input" />
      </label>
      <Button type="submit" variant="secondary" size="sm" fullWidth={false}>Найти</Button>
      {search ? <ButtonRouteLink href={reviewsHref(subject)} variant="ghost" size="sm" fullWidth={false}>Очистить</ButtonRouteLink> : null}
    </form>
  );
}
