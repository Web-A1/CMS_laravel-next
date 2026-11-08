import { LearningSidebar } from "@/components/learning/LearningPathUi";
import { accountApiBaseUrl } from "@/lib/accountAuth";
import { learningCourseDestination, loadLearningSubject } from "@/lib/learningApi";

type CourseSidebarProps = {
  readonly params: Promise<{ subjectSlug: string; courseSlug: string }>;
};

export default async function CourseSidebar({ params }: CourseSidebarProps) {
  const { subjectSlug, courseSlug } = await params;
  const path = `/lk/learning/${encodeURIComponent(subjectSlug)}/${encodeURIComponent(courseSlug)}`;
  const state = await loadLearningSubject(accountApiBaseUrl(), path, subjectSlug);

  if (state.status !== "ready" || !state.data.courses.some((course) => course.slug === courseSlug)) {
    return null;
  }

  return (
    <LearningSidebar
      activeSlug={courseSlug}
      ariaLabel={`Курсы предмета «${state.data.subject.title}»`}
      navigationScope={`courses:${subjectSlug}`}
      items={state.data.courses.map((course) => ({
        id: course.id,
        href: learningCourseDestination(course),
        slug: course.slug,
        title: course.title,
        locked: course.access === "none" && !course.freeLessonAvailable,
      }))}
    />
  );
}
