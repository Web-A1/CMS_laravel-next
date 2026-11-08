"use client";

import Button from "@/components/button/Button";

export default function SiteError({ reset }: { readonly reset: () => void }) {
  return (
    <main
      className="content-shell page-content page-gutter mx-auto w-full bg-white ui-text-primary"
      data-ui="site.error"
      data-state="error"
    >
      <section className="mx-auto grid w-full max-w-xl gap-4 py-8" aria-labelledby="site-error-title">
        <h1 id="site-error-title" className="text-2xl font-bold leading-tight sm:text-3xl">
          Не удалось загрузить страницу
        </h1>
        <p className="text-sm leading-6 ui-text-secondary">
          Сервис временно недоступен. Повторите попытку.
        </p>
        <Button type="button" variant="secondary" fullWidth={false} className="w-full sm:w-fit" onClick={reset}>
          Повторить
        </Button>
      </section>
    </main>
  );
}
