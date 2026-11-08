import type { Metadata } from "next";
import { notFound } from "next/navigation";
import ProtectedFileDeliveryPanel from "@/components/learning/ProtectedFileDeliveryPanel";
import { accountApiBaseUrl } from "@/lib/accountAuth";
import { listOwnedGuides, loadGuideFiles } from "@/lib/guideApi";

export const metadata: Metadata = { title: "Методичка", robots: { index: false, follow: false } };
export default async function OwnedGuidePage({ params }: { readonly params: Promise<{ guideSlug: string }> }) {
  const { guideSlug } = await params;
  if (!/^[a-z0-9-]+$/.test(guideSlug)) notFound();
  const apiBaseUrl = accountApiBaseUrl();
  const [library, files] = await Promise.all([listOwnedGuides(apiBaseUrl), loadGuideFiles(apiBaseUrl, guideSlug)]);
  if (files.status === "not_found") notFound();
  const guide = library.status === "ready" ? library.data.find((item) => item.slug === guideSlug) : undefined;
  return <main className="content-shell page-content page-gutter" data-ui="guides.detail" data-state={files.status}><h1 className="text-3xl font-bold">{guide?.title ?? "Методичка"}</h1><div className="mt-6">{files.status === "ready" && guide ? <ProtectedFileDeliveryPanel apiBaseUrl={apiBaseUrl} route={{ kind: "guide", guideSlug }} files={files.data} /> : <p className="ui-text-secondary" role="status">{files.status === "denied" ? "Доступ к этой методичке не подтверждён." : "Не удалось загрузить материалы. Попробуйте позже."}</p>}</div></main>;
}
