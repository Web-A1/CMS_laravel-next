import type { Metadata } from "next";
import { notFound, redirect } from "next/navigation";

import ReviewsCollection from "@/components/reviews/ReviewsCollection";
import CatalogSearchForm from "@/components/content/CatalogSearchForm";
import CatalogPagination from "@/components/content/CatalogPagination";
import PageIntroLayout from "@/components/content/PageIntroLayout";
import ReviewsFilters from "@/components/reviews/ReviewsFilters";
import { reviewsHref, reviewSubjectOptions } from "@/components/reviews/reviewSubjectOptions";
import { getReviewResult, listReviewsResult } from "@/lib/reviewsApi";
import { isSiteIndexingEnabled } from "@/lib/siteIndexingPolicy";
import { articleSiteUrl } from "@/lib/articleSeo";
import { REVIEW_PAGE_SIZES, catalogPageSize } from "@/lib/catalogPagination";

const pageMetadata: Metadata = {
  title: "Отзывы студентов",
  description: "Отзывы студентов Christmedschool по предметам и курсам медицинского обучения.",
};

type ReviewsPageProps = {
  searchParams: Promise<{
    subject?: string;
    page?: string;
    review?: string;
    q?: string | string[];
    per_page?: string | string[];
  }>;
};

function reviewSearchQuery(params: Awaited<ReviewsPageProps["searchParams"]>): string {
  return typeof params.q === "string" ? params.q.trim().slice(0, 200) : "";
}

function reviewPageSize(value: unknown): number {
  const size = catalogPageSize(value, REVIEW_PAGE_SIZES, 25);
  if (size === null) notFound();
  return size;
}

export async function generateMetadata({ searchParams }: ReviewsPageProps): Promise<Metadata> {
  const params = await searchParams;
  const search = reviewSearchQuery(params);
  const perPage = reviewPageSize(params.per_page);
  if (!search && perPage === 25) return pageMetadata;
  return {
    ...pageMetadata,
    ...(perPage !== 25 ? { alternates: { canonical: `${articleSiteUrl()}${reviewsHref(params.subject, search, perPage, toPage(params.page))}` } } : {}),
    robots: { index: false, follow: isSiteIndexingEnabled(process.env.SITE_INDEXING_ENABLED, process.env.SITE_URL) },
  };
}

function toPage(value: string | undefined): number {
  if (!value) {
    return 1;
  }

  const page = Number(value);
  return Number.isSafeInteger(page) && page > 0 ? page : 1;
}

function toReviewId(value: string | undefined): number | null {
  if (!value) {
    return null;
  }

  const reviewId = Number(value);
  return Number.isSafeInteger(reviewId) && reviewId > 0 ? reviewId : null;
}

export default async function ReviewsPage({ searchParams }: ReviewsPageProps) {
  const params = await searchParams;
  const search = reviewSearchQuery(params);
  const perPage = reviewPageSize(params.per_page);
  const reviewsResult = await listReviewsResult({
    subject: params.subject,
    search,
    page: toPage(params.page),
    perPage,
  });
  const reviews = reviewsResult.data;
  const subjects = reviewsResult.meta.facets.subjects;
  const totalPages = Math.max(1, reviewsResult.meta.lastPage);
  const requestedPage = toPage(params.page);

  if (requestedPage > totalPages) {
    redirect(reviewsHref(params.subject, search, perPage, totalPages));
  }

  const currentPage = Math.min(reviewsResult.meta.page, totalPages);
  const initialReviewId = toReviewId(params.review);
  const initialReviewResult = initialReviewId
    ? await getReviewResult(initialReviewId, { subject: params.subject, search })
    : null;
  const subjectOptions = reviewSubjectOptions(subjects, reviewsResult.meta.totalPublished, search, perPage);

  return (
    <main className="bg-white ui-text-primary" data-ui="reviews.page">
      <section className="reviews-page content-shell page-content page-gutter relative z-0 mx-auto flex w-full flex-col">
        <div className="reviews-page__header" data-ui="reviews.header">
          <PageIntroLayout title={<h1 className="text-3xl font-semibold text-balance">Отзывы студентов</h1>} search={<CatalogSearchForm action="/reviews" id="reviews-search" label="Поиск по отзывам" submitLabel="Найти отзывы" query={search} maxLength={200} dataUi="reviews.search"
            feedback={{ text: `Показано отзывов: ${reviews.length} из ${reviewsResult.meta.total}`, resetHref: search ? reviewsHref(params.subject, undefined, perPage) : undefined }}>
            {params.subject ? <input type="hidden" name="subject" value={params.subject} /> : null}
            {perPage !== 25 ? <input type="hidden" name="per_page" value={perPage} /> : null}
          </CatalogSearchForm>}>
          <div className="reviews-page__intro">
            <p>
              Собрали отзывы студентов по предметам и курсам Christmedschool, чтобы вы могли оценить формат обучения, работу преподавателей и результаты подготовки до выбора программы.
            </p>
          </div>
          </PageIntroLayout>
        </div>

        <ReviewsFilters
          subjects={subjects}
          selectedSubject={params.subject}
          search={search}
          perPage={perPage}
          totalPublished={reviewsResult.meta.totalPublished}
        />

        <div className="reviews-page__body">
          {initialReviewId && !initialReviewResult?.data ? <p className="text-sm ui-text-secondary" role="status" data-ui="reviews.unavailable">Этот отзыв недоступен в выбранной подборке. Выберите другой отзыв или предмет.</p> : null}
          <ReviewsCollection
            key={`${params.subject ?? ""}:${search}:${perPage}:${currentPage}:${initialReviewId ?? ""}`}
            reviews={reviews}
            initialReview={initialReviewResult?.data ?? null}
            subjectOptions={subjectOptions}
            initialReviewId={initialReviewResult?.data ? initialReviewId : null}
            initialSubject={params.subject ?? ""}
            initialSearch={search}
            initialPerPage={perPage}
            initialPage={currentPage}
            initialReviewNavigation={initialReviewResult?.navigation ?? null}
            listClassName="reviews-list"
            dataUiPrefix="reviews"
            emptyMessage={search ? "По вашему запросу отзывов не найдено. Измените запрос или очистите поиск." : undefined}
          />
          <CatalogPagination currentPage={currentPage} totalPages={totalPages} total={reviewsResult.meta.total} perPage={perPage}
            previousHref={reviewsHref(params.subject, search, perPage, currentPage - 1)} nextHref={reviewsHref(params.subject, search, perPage, currentPage + 1)}
            pageSizes={REVIEW_PAGE_SIZES.map((value) => ({ value, href: reviewsHref(params.subject, search, value) }))}
            label="Страницы отзывов" pageSizeLabel="Отзывов на странице" dataUi="reviews.pagination" />
        </div>
      </section>
    </main>
  );
}
