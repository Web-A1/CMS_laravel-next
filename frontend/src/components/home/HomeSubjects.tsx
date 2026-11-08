import SectionTitle from "@/components/content/SectionTitle";
import type { CSSProperties } from "react";
import SubjectCard from "@/components/subject/SubjectCard";
import HomeSubjectsReveal from "@/components/home/HomeSubjectsReveal";
import {
  learningSubjectVisualsBySlug,
  type LearningSubjectCardIllustration,
} from "@/components/learning/learningSubjectVisuals";
import type { SubjectCatalogItem } from "@/lib/catalogApi";

type SubjectCardVariant = "mini" | "short" | "compact" | "reduced" | "medium" | "large" | "tall";

type SubjectCardConfig = {
  readonly variant: SubjectCardVariant;
};

type HomeSubjectCard = {
  readonly illustration: LearningSubjectCardIllustration | null;
  readonly order: number;
  readonly subject: SubjectCatalogItem;
  readonly variant: SubjectCardVariant;
  readonly rowStart: number;
  readonly rowSpan: number;
};

type HomeSubjectsProps = {
  readonly subjects: ReadonlyArray<SubjectCatalogItem>;
};

type MobileArtworkLayout = "bleed" | "compact" | "contained" | "dense" | "balanced" | "wide" | "typography";

const mobileArtworkLayouts: Readonly<Record<string, MobileArtworkLayout>> = {
  anatomiya: "bleed",
  mikrobiologiya: "compact",
  gistologiya: "dense",
  fiziologiya: "balanced",
  "patologicheskaya-anatomiya": "balanced",
  "propedevtika-vnutrennih-boleznej": "dense",
  patofiziologiya: "compact",
  biohimiya: "wide",
  nevrologiya: "wide",
  "luchevaya-diagnostika": "wide",
  "latinskij-yazyk": "typography",
};

const leftColumnConfigs = [
  subjectCard("short"),
  subjectCard("tall"),
  subjectCard("medium"),
  subjectCard("mini"),
  subjectCard("medium"),
  subjectCard("tall"),
  subjectCard("short"),
] as const satisfies ReadonlyArray<SubjectCardConfig>;

const rightColumnConfigs = [
  subjectCard("large"),
  subjectCard("short"),
  subjectCard("compact"),
  subjectCard("medium"),
  subjectCard("large"),
  subjectCard("short"),
  subjectCard("reduced"),
] as const satisfies ReadonlyArray<SubjectCardConfig>;

function subjectCard(variant: SubjectCardVariant): SubjectCardConfig {
  return {
    variant,
  };
}

function buildSubjectCards(
  subjects: ReadonlyArray<{ readonly order: number; readonly subject: SubjectCatalogItem }>,
  configs: ReadonlyArray<SubjectCardConfig>,
): ReadonlyArray<HomeSubjectCard> {
  let rowStart = 1;
  return subjects.map(({ order, subject }, index) => {
    const variant = configs[index]?.variant ?? "compact";
    // Quarter-rem rows retain the approved mobile heights and 0.75rem gaps.
    const rowSpan = { mini: 37, short: 41, reduced: 52, tall: 61, compact: 53, medium: 53, large: 53 }[variant] + 3;
    const card = {
      illustration: learningSubjectVisualsBySlug[subject.slug]?.illustration ?? null,
      order,
      subject,
      variant,
      rowStart,
      rowSpan,
    };
    rowStart += rowSpan;
    return card;
  });
}

function columnSubjects(
  subjects: ReadonlyArray<SubjectCatalogItem>,
  columnIndex: 0 | 1,
): ReadonlyArray<{ readonly order: number; readonly subject: SubjectCatalogItem }> {
  return subjects
    .map((subject, order) => ({ order, subject }))
    .filter(({ order }) => order % 2 === columnIndex);
}

const coursePluralRules = new Intl.PluralRules("ru");

function SubjectTile({ card }: { readonly card: HomeSubjectCard }) {
  const coursesCount = card.subject.courses.length;
  const courseWord = coursePluralRules.select(coursesCount);
  const courseLabel = courseWord === "one" ? "курс" : courseWord === "few" ? "курса" : "курсов";

  return (
    <SubjectCard
      href={`/subjects/${card.subject.slug}`}
      title={card.subject.title}
      titleAs="h3"
      subjectSlug={card.subject.slug}
      illustration={card.illustration}
      className={`home-subject-card home-subject-card--${card.variant}`}
      style={{ "--mobile-column": card.order % 2 + 1, "--mobile-row": card.rowStart, "--mobile-span": card.rowSpan } as CSSProperties}
      data-mobile-artwork={mobileArtworkLayouts[card.subject.slug] ?? "contained"}
      dataUi="home.subjects.card"
      footer={<span className="home-subject-card__course-count" data-ui="home.subjects.card.courseCount">
        <span className="home-subject-card__course-prefix">Включает: </span>
        <span className="home-subject-card__course-number">{coursesCount}</span>{" "}
        <span className="home-subject-card__course-label">{courseLabel}</span>
      </span>}
    />
  );
}

export default function HomeSubjects({ subjects }: HomeSubjectsProps) {
  if (subjects.length === 0) {
    return null;
  }

  const leftColumnCards = buildSubjectCards(columnSubjects(subjects, 0), leftColumnConfigs);
  const rightColumnCards = buildSubjectCards(columnSubjects(subjects, 1), rightColumnConfigs);

  return (
    <section className="home-section home-subjects" id="subjects" data-ui="home.subjects">
      <SectionTitle className="home-subjects__title" data-ui="home.subjects.title">
        курсы для <span>студентов медиков</span>
      </SectionTitle>
      <HomeSubjectsReveal>
        {[...leftColumnCards, ...rightColumnCards].sort((a, b) => a.order - b.order).map((card) => (
          <SubjectTile key={card.subject.slug} card={card} />
        ))}
      </HomeSubjectsReveal>
    </section>
  );
}
