import type { ReviewSubjectFacet } from "@/lib/reviewsApi";

export type ReviewSubjectOption = {
  readonly slug: string;
  readonly title: string;
  readonly href: string;
  readonly reviewsCount: number;
};

export function reviewsHref(subject?: string, query?: string, perPage = 25, page = 1): string {
  const search = new URLSearchParams();
  if (subject) search.set("subject", subject);
  if (query) search.set("q", query);
  if (page > 1) search.set("page", String(page));
  if (perPage !== 25) search.set("per_page", String(perPage));
  return search.size ? `/reviews?${search.toString()}` : "/reviews";
}

export function reviewSubjectOptions(
  subjects: ReadonlyArray<ReviewSubjectFacet>,
  totalPublished: number,
  search?: string,
  perPage = 25,
): ReadonlyArray<ReviewSubjectOption> {
  return [
    {
      slug: "",
      title: "Все",
      href: reviewsHref(undefined, search, perPage),
      reviewsCount: totalPublished,
    },
    ...subjects.map((subject) => ({
      slug: subject.slug,
      title: subject.title,
      href: reviewsHref(subject.slug, search, perPage),
      reviewsCount: subject.reviewsCount,
    })),
  ];
}
