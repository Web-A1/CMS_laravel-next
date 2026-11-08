import type { Metadata } from "next";
import Link from "next/link";

import { listSubjects } from "@/lib/catalogApi";

export const metadata: Metadata = {
  title: "Предметы",
  description: "Каталог предметов Christmedschool с курсами для студентов медицинских вузов.",
};

export default async function SubjectsPage() {
  const subjects = await listSubjects();

  return (
    <main className="bg-white ui-text-primary">
      <section className="content-shell page-content page-gutter relative z-0 mx-auto w-full">
        <h1 className="text-3xl font-semibold">Предметы</h1>
        <p className="mt-3 max-w-2xl ui-text-secondary">Выберите дисциплину для перехода к курсам.</p>

        <ul className="mt-6 grid gap-3">
          {subjects.map((subject) => (
            <li key={subject.slug}>
              <Link
                href={`/subjects/${subject.slug}`}
                className="block rounded-[var(--radius-control)] border border-[var(--color-border-subtle)] px-4 py-3 text-lg font-medium transition hover:border-[var(--color-border-control)] ui-surface-muted-hover"
              >
                {subject.title}
              </Link>
            </li>
          ))}
        </ul>
      </section>
    </main>
  );
}
