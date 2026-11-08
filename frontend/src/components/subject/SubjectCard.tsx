import Link from "next/link";
import type { ComponentProps, ReactNode } from "react";

import GeneratedImage, { type StaticGeneratedImageAsset } from "@/components/content/GeneratedImage";
import type { LearningSubjectCardIllustration } from "@/components/learning/learningSubjectVisuals";

import "./subject-card.css";

type SubjectCardProps = Omit<ComponentProps<typeof Link>, "children" | "title"> & {
  readonly title: string;
  readonly titleAs?: "span" | "h3";
  readonly subjectSlug: string;
  readonly illustration: LearningSubjectCardIllustration | null;
  readonly image?: StaticGeneratedImageAsset;
  readonly artworkClassName?: string;
  readonly preloadIllustration?: boolean;
  readonly footer: ReactNode;
  readonly dataUi: string;
};

export default function SubjectCard({
  title,
  titleAs: Title = "span",
  subjectSlug,
  illustration,
  image,
  artworkClassName = "",
  preloadIllustration = false,
  footer,
  dataUi,
  className = "",
  ...linkProps
}: SubjectCardProps) {
  return (
    <Link {...linkProps} className={`subject-card ${className}`} data-subject={subjectSlug} data-ui={dataUi}>
      <span className="subject-card__visual" aria-hidden="true" data-ui={`${dataUi}.illustration`}>
        {illustration?.kind === "image" ? (
          <span
            className="subject-card__illustration"
            style={{ opacity: illustration.style.opacity, transform: illustration.style.transform }}
          >
            <GeneratedImage
              image={image ?? illustration.image}
              alt=""
              className={artworkClassName}
              loading={preloadIllustration ? "eager" : "lazy"}
              decoding="async"
              fetchPriority={preloadIllustration ? "high" : "low"}
            />
          </span>
        ) : illustration?.kind === "typography" ? (
          <span className={`subject-card__typography ${artworkClassName}`}>
            {illustration.words.map((word) => (
              <span key={word.text} className={word.className} style={word.style}>{word.text}</span>
            ))}
          </span>
        ) : (
          <span className="subject-card__placeholder">{title.trim().charAt(0).toUpperCase()}</span>
        )}
      </span>
      <Title className="subject-card__title" data-ui={`${dataUi}.title`}>{title}</Title>
      <span className="subject-card__footer">{footer}</span>
    </Link>
  );
}
