"use client";

import type { FormEvent, ReactNode } from "react";
import Form from "next/form";
import Link from "next/link";
import "./catalog-search.css";

export default function CatalogSearchForm({ action, id, label, submitLabel, query, maxLength, dataUi, feedback, children }: {
  readonly action: string;
  readonly id: string;
  readonly label: string;
  readonly submitLabel: string;
  readonly query: string;
  readonly maxLength: number;
  readonly dataUi: string;
  readonly feedback?: {
    readonly text: string;
    readonly resetHref?: string;
  };
  readonly children?: ReactNode;
}) {
  function prepareSearch(event: FormEvent<HTMLFormElement>) {
    const form = event.currentTarget;
    const query = form.elements.namedItem("q") as HTMLInputElement | null;
    const searching = Boolean(query?.value.trim());
    // Next Form serializes after onSubmit, so both Enter and the button use this scope.
    for (const filter of form.querySelectorAll<HTMLInputElement>('input[type="hidden"]')) {
      if (filter.name === "subject" || filter.name === "category") filter.disabled = searching;
    }
  }

  return <Form action={action} onSubmit={prepareSearch} role="search" aria-label={label} className="grid min-w-0 gap-3" data-ui={dataUi}>
    <label className="sr-only" htmlFor={id}>{label}</label>
    {children}
    <div className="relative min-w-0">
      <input key={query} id={id} type="search" name="q" defaultValue={query} maxLength={maxLength}
        placeholder="Поиск по содержимому" className="ui-input catalog-search__input h-8 w-full rounded-[var(--radius-indicator)] border ps-10 pe-3 text-base sm:text-sm" data-ui={`${dataUi}.input`} />
      <button type="submit" className="ui-icon-control ui-icon-control--round catalog-search__submit" aria-label={submitLabel} data-ui={`${dataUi}.submit`}>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4 4" /></svg>
      </button>
    </div>
    {feedback ? <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1" data-ui={`${dataUi}.results`}>
      <p className="ui-meta-text ui-meta-text--regular" role="status">{feedback.text}</p>
      {feedback.resetHref ? <Link href={feedback.resetHref} className="ui-text-link ui-text-link--meta ms-auto" data-ui={`${dataUi}.reset`}>Сбросить</Link> : null}
    </div> : null}
  </Form>;
}
