import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { findSubject } from "@/lib/catalogApi";

type SubjectPageProps = {
  params: Promise<{ subjectSlug: string }>;
};

export async function generateMetadata({ params }: SubjectPageProps): Promise<Metadata> {
  const { subjectSlug } = await params;
  const subject = await findSubject(subjectSlug);

  if (!subject) {
    return {
      title: "Предмет не найден",
      robots: {
        index: false,
        follow: false,
      },
    };
  }

  return {
    title: subject.title,
    description: `Курсы Christmedschool по предмету ${subject.title}: выберите программу и перейдите к оформлению доступа.`,
  };
}

export default async function SubjectPage({ params }: SubjectPageProps) {
  const { subjectSlug } = await params;
  const subject = await findSubject(subjectSlug);

  if (!subject) {
    notFound();
  }

  return (
    <main className="bg-white ui-text-primary">
      <section className="content-shell page-content page-gutter relative z-0 mx-auto w-full">
        <h1 className="text-3xl font-semibold">Предмет: {subject.title}</h1>
        <p className="mt-3 max-w-2xl ui-text-secondary">Выберите курс по выбранному предмету.</p>

        <ul className="mt-6 grid gap-3">
          {subject.courses.map((course) => (
            <li key={course.courseSlug}>
              <Link
                href={`/subjects/${subjectSlug}/${course.courseSlug}`}
                className="block rounded-[var(--radius-control)] border border-[var(--color-border-subtle)] px-4 py-3 text-lg font-medium transition hover:border-[var(--color-border-control)] ui-surface-muted-hover"
              >
                {course.title}
              </Link>
            </li>
          ))}
        </ul>
      </section>
    </main>
  );
}
