import type { Metadata } from "next";
import { isSiteIndexingEnabled } from "@/lib/siteIndexingPolicy";
import Link from "next/link";
import { notFound } from "next/navigation";
import { cache } from "react";
import "../../articles.css";
import PillStrip from "@/components/content/PillStrip";
import ArticleCard from "@/components/content/ArticleCard";
import PageIntroLayout from "@/components/content/PageIntroLayout";
import CatalogSearchForm from "@/components/content/CatalogSearchForm";
import CatalogPagination from "@/components/content/CatalogPagination";
import { ARTICLE_PAGE_SIZES, catalogPageSize } from "@/lib/catalogPagination";
import { articleCategories, articleSubjects, listArticlesResult } from "@/lib/articlesApi";
import { articleListPath, articleSiteUrl } from "@/lib/articleSeo";

type Props = { readonly searchParams: Promise<{ subject?: string | string[]; category?: string | string[]; page?: string | string[]; q?: string | string[]; per_page?: string | string[] }> };
const categories = cache(articleCategories);
const subjects = cache(articleSubjects);
const articlesTitle = "Медицинские статьи для студентов";
const articlesDescription = "Практикующие врачи разбирают темы по анатомии, биохимии и другим медицинским дисциплинам, помогая понять теорию и её связь с клинической практикой.";

async function selection(params: Awaited<Props["searchParams"]>) {
  const perPage = catalogPageSize(params.per_page, ARTICLE_PAGE_SIZES, 25);
  if (perPage === null) notFound();
  if (typeof params.subject !== "undefined" && typeof params.subject !== "string") notFound();
  if (typeof params.category !== "undefined" && typeof params.category !== "string") notFound();
  if (typeof params.page !== "undefined" && typeof params.page !== "string") notFound();
  if (typeof params.q !== "undefined" && typeof params.q !== "string") notFound();
  const q = params.q?.trim() || undefined;
  if (q && Array.from(q).length > 120) notFound();
  if (params.subject && params.category) notFound();
  const allSubjects = await subjects();
  const selected = params.subject ? allSubjects.find((group) => group.slug === params.subject) : undefined;
  if (params.subject && !selected) notFound();
  const legacyCategory = params.category || undefined;
  if (legacyCategory && !(await categories()).some((group) => group.slug === legacyCategory)) notFound();
  if (params.page && !/^[1-9]\d*$/u.test(params.page)) notFound();
  const page = Number(params.page ?? 1);
  if (!Number.isSafeInteger(page) || page < 1) notFound();
  const filters = { subject: selected?.slug, category: legacyCategory, ...(q ? { q } : {}) };
  return { groups: allSubjects.filter((group) => group.count > 0), selected, legacyCategory, filters, page, perPage, q };
}

export async function generateMetadata({ searchParams }: Props): Promise<Metadata> {
  const { selected, legacyCategory, filters, page, perPage, q } = await selection(await searchParams);
  const title = `${selected?.title ?? (legacyCategory ? "Статьи" : articlesTitle)}${page > 1 ? ` — страница ${page}` : ""}`;
  return {
    title,
    description: selected ? `Обучающие статьи по теме «${selected.title}» от преподавателей Christmedschool.` : articlesDescription,
    alternates: { canonical: `${articleSiteUrl()}${articleListPath(filters, page, perPage)}` },
    robots: isSiteIndexingEnabled(process.env.SITE_INDEXING_ENABLED, process.env.SITE_URL) ? { index: !q && perPage === 25, follow: true } : { index: false, follow: false },
  };
}

export default async function ArticlesPage({ searchParams }: Props) {
  const { groups, selected, legacyCategory, filters, page, perPage, q } = await selection(await searchParams);
  const result = await listArticlesResult({ ...filters, page, limit: perPage });
  if (page > result.meta.lastPage) notFound();
  const total = selected || legacyCategory ? (await listArticlesResult({ limit: 1, ...(q ? { q } : {}) })).meta.total : result.meta.total;
  const searchCounts = q ? new Map((await subjects(q)).map((group) => [group.id, group.count])) : null;
  const countedGroups = groups.map((group) => searchCounts ? { ...group, count: searchCounts.get(group.id) ?? 0 } : group);
  return <main className="articles-page" data-ui="articles.page">
    <section className="articles-page__shell content-shell page-content page-gutter relative mx-auto w-full">
      <header className="articles-page__header max-sm:[&_input::placeholder]:text-sm sm:[&_input::placeholder]:text-xs">
        <PageIntroLayout title={<h1 className="ui-section-title">{selected?.title ?? articlesTitle}</h1>} search={<CatalogSearchForm action="/articles" id="article-search" label="Поиск по статьям" submitLabel="Найти статьи" query={q ?? ""} maxLength={120} dataUi="articles.search"
          feedback={q ? { text: `Найдено статей: ${result.meta.total}`, resetHref: articleListPath({ subject: filters.subject, category: filters.category }, 1, perPage) } : undefined}>
          {selected ? <input type="hidden" name="subject" value={selected.slug} /> : null}
          {legacyCategory ? <input type="hidden" name="category" value={legacyCategory} /> : null}
          {perPage !== 25 ? <input type="hidden" name="per_page" value={perPage} /> : null}
        </CatalogSearchForm>}>
        <div className="articles-page__intro">
          <p className="ui-card-text ui-card-text--large">{articlesDescription}</p>
        </div>
        </PageIntroLayout>
        {groups.length ? <PillStrip className="articles-page__pills" ariaLabel="Разделы статей">
          {[{ slug: "", title: "Все", count: total }, ...countedGroups].map((group) => <Link
            key={group.slug} href={articleListPath({ subject: group.slug || undefined, q }, 1, perPage)} className="ui-pill-control"
            aria-current={!legacyCategory && (selected?.slug ?? "") === group.slug ? "page" : undefined}
            data-active={!legacyCategory && (selected?.slug ?? "") === group.slug ? "true" : undefined}>
            <span className="ui-pill-control__label">{group.title}</span><span className="ui-pill-control__count">{group.count}</span>
          </Link>)}
        </PillStrip> : null}
      </header>
      {result.data.length ? <ul className="articles-page__list grid list-none grid-cols-1 gap-x-12 gap-y-16 p-0 sm:grid-cols-2 lg:grid-cols-3" data-ui="articles.list">
        {result.data.map((article) => <li key={article.id} className="min-w-0"><ArticleCard article={article} /></li>)}
      </ul> : <p className="ui-card-text" data-ui="articles.empty">{q ? `По запросу «${q}» ничего не найдено. Попробуйте другое слово или сбросьте поиск.` : "В этом разделе пока нет статей."}</p>}
      <CatalogPagination currentPage={page} totalPages={result.meta.lastPage} total={result.meta.total} perPage={perPage}
        previousHref={articleListPath(filters, page - 1, perPage)} nextHref={articleListPath(filters, page + 1, perPage)}
        pageSizes={ARTICLE_PAGE_SIZES.map((value) => ({ value, href: articleListPath(filters, 1, value) }))}
        label="Страницы статей" pageSizeLabel="Статей на странице" dataUi="articles.pagination" />
    </section>
  </main>;
}
