import { notFound, permanentRedirect } from "next/navigation";
import { legacyArticleTarget } from "@/lib/articlesApi";

export default async function LegacyArticlePage({ params }: { readonly params: Promise<{ legacyPath?: string[] }> }) {
  const { legacyPath = [] } = await params;
  const target = await legacyArticleTarget(`/news${legacyPath.length ? `/${legacyPath.join("/")}` : ""}`);
  if (!target) notFound();
  permanentRedirect(target);
}
