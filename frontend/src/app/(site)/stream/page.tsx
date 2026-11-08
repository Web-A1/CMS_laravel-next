import type { Metadata } from "next";
export const metadata: Metadata = { title: "Трансляция" };
export default function StreamPage() {
  return <main className="content-shell page-content page-gutter" data-ui="stream.screen" data-state="unavailable"><h1 className="text-3xl font-bold">Трансляция</h1><p className="mt-4 ui-text-secondary" role="status">Сейчас нет подтверждённой актуальной трансляции.</p></main>;
}
