import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import CoursePurchaseCta from "@/components/course/CoursePurchaseCta";
import { findCourseBySlugs } from "@/lib/catalogApi";
import { adaptCourseCheckout } from "@/lib/commerceApi";
import { learningAccessNoticeVisible } from "@/lib/learningApi";

type CoursePageProps = {
  params: Promise<{ subjectSlug: string; courseSlug: string }>;
  searchParams: Promise<{ notice?: string | ReadonlyArray<string> }>;
};

function lessonCountLabel(count: number): string {
  const normalizedCount = Math.abs(count);
  const lastTwoDigits = normalizedCount % 100;
  const lastDigit = normalizedCount % 10;
  const lessonWord =
    lastTwoDigits >= 11 && lastTwoDigits <= 14
      ? "уроков"
      : lastDigit === 1
        ? "урок"
        : lastDigit >= 2 && lastDigit <= 4
          ? "урока"
          : "уроков";

  return `${count} ${lessonWord}`;
}

export async function generateMetadata({ params }: CoursePageProps): Promise<Metadata> {
  const { subjectSlug, courseSlug } = await params;
  const course = await findCourseBySlugs(subjectSlug, courseSlug);

  if (!course) {
    return {
      title: "Курс не найден",
      robots: {
        index: false,
        follow: false,
      },
    };
  }

  return {
    title: course.title,
    description: `Курс Christmedschool по предмету ${course.subjectTitle}: уроки курса, доступ и оформление покупки.`,
  };
}

export default async function CoursePage({ params, searchParams }: CoursePageProps) {
  const { subjectSlug, courseSlug } = await params;
  const { notice } = await searchParams;
  const course = await findCourseBySlugs(subjectSlug, courseSlug);

  if (!course) {
    notFound();
  }

  return (
    <main className="bg-white ui-text-primary" data-ui="site.course">
      <article className="content-shell page-content page-gutter relative z-0 mx-auto grid w-full gap-8">
        <header className="grid w-full max-w-2xl gap-4 lg:max-w-4xl">
          <Link
            href={`/subjects/${subjectSlug}`}
            className="w-fit text-sm font-medium leading-5 ui-text-muted-ink underline-offset-4 ui-text-soft-ink-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[var(--color-brand)]"
            data-ui="site.course.subjectLink"
          >
            {course.subjectTitle}
          </Link>

          <h1
            className="max-w-4xl text-balance text-3xl font-bold leading-tight ui-text-primary sm:text-4xl"
            data-ui="site.course.title"
          >
            {course.title}
          </h1>

          {learningAccessNoticeVisible(notice) ? (
            <p
              className="max-w-2xl text-pretty text-sm leading-6 ui-text-muted-ink"
              role="status"
              data-ui="site.course.accessNotice"
            >
              {adaptCourseCheckout(course)
                ? "Доступ к курсу пока закрыт. Можно посмотреть программу и добавить курс в корзину."
                : "Доступ к курсу пока закрыт. Можно посмотреть программу курса."}
            </p>
          ) : null}

          <CoursePurchaseCta key={course.itemId} itemId={course.itemId} available={adaptCourseCheckout(course) !== null} />
        </header>

        {course.lessons.length ? (
          <section className="grid w-full max-w-2xl gap-4" aria-labelledby="site-course-program-title" data-ui="site.course.program">
            <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
              <h2 id="site-course-program-title" className="text-xl font-bold leading-tight ui-text-primary">
                Программа
              </h2>
              <p className="text-sm leading-5 ui-text-muted-ink tabular-nums" data-ui="site.course.lessonsCount">
                {lessonCountLabel(course.lessons.length)}
              </p>
            </div>

            <ol className="grid border-t border-zinc-200/80 ui-text-tertiary" data-ui="site.course.lessons">
              {course.lessons.map((lesson, index) => (
                <li
                  key={lesson.slug}
                  className="grid grid-cols-[2.25rem_minmax(0,1fr)] gap-3 border-b border-zinc-200/80 py-3"
                  data-ui="site.course.lesson"
                >
                  <span className="text-sm leading-6 ui-text-muted tabular-nums" aria-hidden="true">
                    {String(index + 1).padStart(2, "0")}
                  </span>
                  <span className="text-pretty text-sm font-medium leading-6 ui-text-tertiary sm:text-base">
                    {lesson.title}
                  </span>
                </li>
              ))}
            </ol>
          </section>
        ) : null}
      </article>
    </main>
  );
}
