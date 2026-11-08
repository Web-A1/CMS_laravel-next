import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Боксы",
  description: "Боксы Christmedschool — наборы курсов, которые можно приобрести вместе. Комплексные предложения скоро появятся в этом разделе.",
};

export default function BoxesPage() {
  return (
    <main className="bg-white ui-text-primary" data-ui="boxes.page">
      <section className="content-shell page-content page-gutter relative z-0 mx-auto w-full" data-ui="boxes.intro">
        <header className="max-w-2xl">
          <h1 className="ui-section-title">Боксы</h1>
          <p className="mt-4 ui-card-text ui-card-text--large">
            Наборы разных курсов, которые можно будет приобрести вместе.
          </p>
        </header>
        <p className="mt-6 ui-card-text" data-ui="boxes.emptyState">
          Готовим комплексные предложения. Они скоро появятся в этом разделе.
        </p>
      </section>
    </main>
  );
}
