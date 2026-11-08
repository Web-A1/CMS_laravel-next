import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import "../../faq.css";

import PillStrip from "@/components/content/PillStrip";
import FaqDetailsList from "@/components/faq/FaqDetailsList";
import PageIntroLayout from "@/components/content/PageIntroLayout";
import CatalogSearchForm from "@/components/content/CatalogSearchForm";
import { listFaqsResult, type PublicFaqItem } from "@/lib/faqApi";
import { isSiteIndexingEnabled } from "@/lib/siteIndexingPolicy";

const pageMetadata: Metadata = {
  title: "FAQ",
  description: "Ответы на частые вопросы о курсах Christmedschool, оплате, доступе к платформе и учебных материалах.",
};

type FaqGroup = {
  readonly key: string;
  readonly title: string;
  readonly items: ReadonlyArray<PublicFaqItem>;
};

type FaqPageProps = {
  readonly searchParams: Promise<{
    category?: string;
    q?: string | string[];
  }>;
};

function faqSearchQuery(params: Awaited<FaqPageProps["searchParams"]>): string {
  if (typeof params.q !== "undefined" && typeof params.q !== "string") notFound();
  const query = params.q?.trim() ?? "";
  if (Array.from(query).length > 200) notFound();
  return query;
}

export async function generateMetadata({ searchParams }: FaqPageProps): Promise<Metadata> {
  if (!faqSearchQuery(await searchParams)) return pageMetadata;
  return { ...pageMetadata, robots: { index: false, follow: isSiteIndexingEnabled(process.env.SITE_INDEXING_ENABLED, process.env.SITE_URL) } };
}

function faqCategoryHref(category?: string, query?: string): string {
  const params = new URLSearchParams();
  if (category) params.set("category", category);
  if (query) params.set("q", query);
  return params.size ? `/faq?${params}` : "/faq";
}

function groupFaqsByCategory(faqs: ReadonlyArray<PublicFaqItem>): ReadonlyArray<FaqGroup> {
  const groups = new Map<string, { title: string; items: PublicFaqItem[] }>();

  for (const item of faqs) {
    const key = item.category?.slug ?? "general";
    const title = item.category?.title ?? "Общие вопросы";
    const group = groups.get(key);

    if (group) {
      group.items.push(item);
    } else {
      groups.set(key, { title, items: [item] });
    }
  }

  return Array.from(groups, ([key, group]) => ({
    key,
    title: group.title,
    items: group.items,
  }));
}

export default async function FaqPage({ searchParams }: FaqPageProps) {
  const params = await searchParams;
  const query = faqSearchQuery(params);
  const faqResult = await listFaqsResult({
    placement: "faq.index",
    fetchMode: "live",
  });
  const groups = groupFaqsByCategory(faqResult.data);
  const selectedGroup = params.category ? groups.find((group) => group.key === params.category) : undefined;
  const activeCategory = selectedGroup?.key;
  const categoryFaqs = selectedGroup?.items ?? faqResult.data;
  const needle = query.toLocaleLowerCase("ru");
  const visibleFaqs = query ? categoryFaqs.filter((item) => `${item.question} ${item.answer}`.toLocaleLowerCase("ru").includes(needle)) : categoryFaqs;
  const totalFaqs = faqResult.data.length;

  return (
    <main className="faq-page" data-ui="faq.page">
      <section className="faq-page__shell content-shell page-content page-gutter relative z-0 mx-auto w-full">
        <header className="faq-page__header">
          <PageIntroLayout title={<h1 className="ui-section-title">FAQ</h1>} search={<CatalogSearchForm action="/faq" id="faq-search" label="Поиск по вопросам" submitLabel="Найти ответы" query={query} maxLength={200} dataUi="faq.search"
            feedback={query ? { text: `Найдено ответов: ${visibleFaqs.length}`, resetHref: faqCategoryHref(activeCategory) } : undefined}>
            {activeCategory ? <input type="hidden" name="category" value={activeCategory} /> : null}
          </CatalogSearchForm>}>
          <div className="faq-page__intro">
            <p>
              Собрали ответы на частые вопросы о покупке курсов, доступе к платформе, сроках обучения и материалах.
            </p>
          </div>
          </PageIntroLayout>

          {groups.length > 0 ? (
            <PillStrip className="faq-page__pills" ariaLabel="Разделы FAQ">
              <Link
                className="ui-pill-control faq-page__pill"
                href={faqCategoryHref(undefined, query)}
                scroll={false}
                aria-current={activeCategory ? undefined : "page"}
                data-active={activeCategory ? undefined : "true"}
                data-ui="faq.filters.category.all"
              >
                <span className="ui-pill-control__label">все</span>
                <span className="ui-pill-control__count">{totalFaqs}</span>
              </Link>
              {groups.map((group) => (
                <Link
                  key={group.key}
                  className="ui-pill-control faq-page__pill"
                  href={faqCategoryHref(group.key, query)}
                  scroll={false}
                  aria-current={activeCategory === group.key ? "page" : undefined}
                  data-active={activeCategory === group.key ? "true" : undefined}
                  data-ui={`faq.filters.category.${group.key}`}
                >
                  <span className="ui-pill-control__label">{group.title}</span>
                  <span className="ui-pill-control__count">{group.items.length}</span>
                </Link>
              ))}
            </PillStrip>
          ) : null}
        </header>

        {visibleFaqs.length > 0 ? (
          <div className="faq-page__body" data-ui="faq.body">
            <FaqDetailsList
              faqs={visibleFaqs}
              className="faq-page__list"
              itemClassName="faq-page__item"
              summaryClassName="faq-page__summary"
              answerClassName="faq-page__answer whitespace-pre-line"
              dataUi={activeCategory ? `faq.category.${activeCategory}.list` : "faq.list"}
            />
          </div>
        ) : (
          <p className="faq-page__empty ui-card-text" data-ui="faq.empty">
            {query ? `По запросу «${query}» ничего не найдено. Попробуйте другое слово или сбросьте поиск.` : "Вопросы пока готовятся."}
          </p>
        )}
      </section>
    </main>
  );
}
