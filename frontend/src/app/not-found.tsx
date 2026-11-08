import { ButtonLink } from "@/components/button/Button";

export default function NotFound() {
  return (
    <main className="content-shell page-gutter page-content page-content--standalone" data-ui="notFound">
      <section aria-labelledby="not-found-title" className="flex flex-col items-start gap-4">
        <p className="ui-meta-text">Ошибка 404</p>
        <h1 id="not-found-title" className="ui-section-title">
          Страница не найдена
        </h1>
        <p className="ui-card-text ui-card-text--large">Проверьте адрес или вернитесь на главную страницу.</p>
        <ButtonLink href="/" fullWidth={false}>
          На главную
        </ButtonLink>
      </section>
    </main>
  );
}
