import type { Metadata } from "next";
import TextLink from "@/components/content/TextLink";
import { accountApiBaseUrl } from "@/lib/accountAuth";
import { listOwnedGuides } from "@/lib/guideApi";

export const metadata: Metadata = { title: "Мои методички", robots: { index: false, follow: false } };
export default async function OwnedGuidesPage() {
  const result = await listOwnedGuides(accountApiBaseUrl());
  return <main className="content-shell page-content page-gutter" data-ui="guides.library" data-state={result.status}><h1 className="text-3xl font-bold">Мои методички</h1>
    {result.status === "ready" ? result.data.length ? <ul className="mt-5 divide-y divide-[var(--color-border-subtle)]">{result.data.map((guide) => <li className="py-4" key={guide.id}><TextLink href={`/lk/guides/${guide.slug}`}>{guide.title}</TextLink></li>)}</ul> : <p className="mt-5 ui-text-secondary">У вас пока нет купленных методичек.</p> : <p className="mt-5 ui-text-secondary" role="status">{result.status === "denied" ? "Доступ к методичкам не подтверждён." : "Не удалось загрузить методички. Попробуйте позже."}</p>}
  </main>;
}
