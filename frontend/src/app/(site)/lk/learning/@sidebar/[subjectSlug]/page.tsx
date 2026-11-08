import { LearningSidebar } from "@/components/learning/LearningPathUi";
import { accountApiBaseUrl } from "@/lib/accountAuth";
import { loadLearningIndex } from "@/lib/learningApi";

type SubjectSidebarProps = {
  readonly params: Promise<{ subjectSlug: string }>;
};

export default async function SubjectSidebar({ params }: SubjectSidebarProps) {
  const { subjectSlug } = await params;
  const path = `/lk/learning/${encodeURIComponent(subjectSlug)}`;
  const state = await loadLearningIndex(accountApiBaseUrl(), path);

  if (state.status !== "ready" || !state.data.subjects.some((subject) => subject.slug === subjectSlug)) {
    return null;
  }

  return (
    <LearningSidebar
      activeSlug={subjectSlug}
      ariaLabel="Предметы обучения"
      navigationScope="subjects"
      items={state.data.subjects.map((subject) => ({
        id: subject.id,
        href: subject.canonicalPath,
        slug: subject.slug,
        title: subject.title,
      }))}
    />
  );
}
