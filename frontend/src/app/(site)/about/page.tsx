import type { Metadata } from "next";

import { findPublicPage, type PublicPageDetail } from "@/lib/publicPagesApi";

type AboutSource = "api" | "fallback";

const fallbackAboutPage: PublicPageDetail = {
  slug: "about",
  title: "О школе",
  description: "О Christmedschool, подходе к обучению и команде преподавателей медицинской школы.",
  body: [
    "Christmedschool помогает студентам медицинских вузов разбираться в сложных дисциплинах через понятные объяснения, структурные конспекты и клинические примеры.",
    "Основатель школы Демо Автор 1 - врач-уролог и ассистент кафедры анатомии Медицинского университета Петровского. Курсы школы строятся так, чтобы теория не оставалась сухой зубрежкой, а связывалась с реальными задачами врача.",
    "Полная страница о школе готовится. Материалы и подробности о команде будут дополняться по мере утверждения контента.",
  ].join("\n\n"),
  sortOrder: 0,
  publishedAt: null,
  seoTitle: null,
  seoDescription: "О Christmedschool, подходе к обучению и команде преподавателей медицинской школы.",
};

function aboutBodyParagraphs(body: string): ReadonlyArray<string> {
  return body
    .split(/\n{2,}/u)
    .map((part) => part.trim())
    .filter(Boolean);
}

async function loadAboutPage(): Promise<{ readonly page: PublicPageDetail; readonly source: AboutSource }> {
  const page = await findPublicPage("about");

  if (page) {
    return { page, source: "api" };
  }

  return { page: fallbackAboutPage, source: "fallback" };
}

export async function generateMetadata(): Promise<Metadata> {
  const { page } = await loadAboutPage();

  return {
    title: page.seoTitle ?? page.title,
    description: page.seoDescription ?? page.description ?? fallbackAboutPage.description,
  };
}

export default async function AboutPage() {
  const { page, source } = await loadAboutPage();
  const paragraphs = aboutBodyParagraphs(page.body);

  return (
    <main className="bg-white ui-text-primary" data-source={source} data-ui="about.page">
      <section className="content-shell page-content page-gutter relative z-0 mx-auto w-full">
        <span hidden data-ui={`about.source.${source}`} />
        <h1 className="ui-section-title">{page.title}</h1>
        {page.description ? <p className="mt-4 max-w-2xl ui-card-text ui-card-text--large">{page.description}</p> : null}
        <div className="mt-6 grid max-w-2xl gap-4" data-ui="about.content">
          {paragraphs.map((paragraph) => (
            <p className="ui-card-text" key={paragraph}>
              {paragraph}
            </p>
          ))}
        </div>
      </section>
    </main>
  );
}
