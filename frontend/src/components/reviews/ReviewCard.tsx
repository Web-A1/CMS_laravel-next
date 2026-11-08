import ReviewMediaImage from "./ReviewMediaImage";

import { SurfaceButton } from "@/components/content/SurfaceCard";
import type { PublicReview, ReviewMediaItem } from "@/lib/reviewsApi";
import ReviewAuthorAvatar from "./ReviewAuthorAvatar";
import { REVIEW_PREVIEW_IMAGE_SIZES } from "./reviewImageConfig";
import "./review-card.css";

type ReviewPreviewCardProps = {
  readonly review: PublicReview;
  readonly className?: string;
  readonly dataUi?: string;
  readonly href?: string;
  readonly socialDesktop?: boolean;
  readonly tabIndex?: number;
  readonly onOpen?: (review: PublicReview, trigger: HTMLButtonElement) => void;
};

type ReviewMediaVariant = "portrait" | "non-portrait";

export type ReviewCardView = Pick<PublicReview, "displayName" | "authorPhotoUrl" | "authorPhotoVariants" | "text" | "reviewWrittenAt" | "media"> & {
  readonly subject: { readonly title: string } | null;
  readonly course: { readonly title: string; readonly shortTitle: string | null } | null;
};

export function reviewMediaAlt(media: ReviewMediaItem, review: PublicReview): string {
  return media.alt ?? `Скриншот отзыва: ${review.displayName}`;
}

function reviewMediaVariant(media: ReviewMediaItem | undefined): ReviewMediaVariant {
  if (!media?.width || !media.height) {
    return "portrait";
  }

  return media.width / media.height > 0.8 ? "non-portrait" : "portrait";
}

const reviewDateFormatter = new Intl.DateTimeFormat("ru-RU", {
  day: "numeric",
  month: "long",
  timeZone: "UTC",
  year: "numeric",
});

export function reviewTag(review: Pick<ReviewCardView, "subject" | "course">): string {
  if (review.subject && review.course) {
    const courseTitle = review.course.shortTitle ?? review.course.title;

    if (review.subject.title.trim().toLowerCase() === courseTitle.trim().toLowerCase()) {
      return review.subject.title;
    }

    return `${review.subject.title} · ${courseTitle}`;
  }

  if (review.subject) {
    return review.subject.title;
  }

  return "Все";
}

export function reviewDate(review: Pick<ReviewCardView, "reviewWrittenAt">): string | null {
  const value = review.reviewWrittenAt;

  if (!value) {
    return null;
  }

  const timestamp = Date.parse(value);

  if (Number.isNaN(timestamp)) {
    return null;
  }

  return reviewDateFormatter.format(new Date(timestamp));
}

export function reviewMeta(review: PublicReview): string {
  const formattedDate = reviewDate(review);

  return formattedDate ? `${reviewTag(review)} · ${formattedDate}` : reviewTag(review);
}

export function reviewPreviewText(review: Pick<ReviewCardView, "text">): string {
  return review.text.replace(/\s+/g, " ").trim();
}

export function reviewTextParagraphs(review: PublicReview): ReadonlyArray<string> {
  return review.text
    .split(/\n{2,}/)
    .map((paragraph) => paragraph.replace(/\s+/g, " ").trim())
    .filter(Boolean);
}

export function ReviewCardContent({ review, socialDesktop = false }: { readonly review: ReviewCardView; readonly socialDesktop?: boolean }) {
  const previewMedia = review.media[0];
  const formattedDate = reviewDate(review);

  return (
    <>
      <div className="review-card__social-header">
        <span className="review-card__portrait" aria-hidden="true">
          <ReviewAuthorAvatar src={review.authorPhotoUrl} variants={review.authorPhotoVariants} sizes={socialDesktop ? "(min-width: 48rem) 52px, 44px" : undefined} />
        </span>
        <div className="review-card__identity">
          {review.displayName ? <p className="ui-card-title">{review.displayName}</p> : null}
          <p className="ui-meta-text">{reviewTag(review)}</p>
        </div>
      </div>
      <div className={previewMedia ? "review-card__body review-card__body--with-media" : "review-card__body"}>
        {previewMedia ? (
          <span className="review-card__media-preview" aria-hidden="true">
            <ReviewMediaImage
              role="preview"
              variants={previewMedia.variants}
              className="review-card__media-image"
              src={previewMedia.url}
              alt=""
              width={previewMedia.width ?? 144}
              height={previewMedia.height ?? 200}
              sizes={REVIEW_PREVIEW_IMAGE_SIZES}
            />
          </span>
        ) : null}
        <p className="ui-card-text review-card__text">{reviewPreviewText(review)}</p>
      </div>
      <div className="review-card__footer">
        <p className="ui-meta-text review-card__byline">
          {formattedDate ? (
            <time className="review-card__date" dateTime={review.reviewWrittenAt ?? undefined}>
              {formattedDate}
            </time>
          ) : null}
        </p>
      </div>
    </>
  );
}

export default function ReviewPreviewCard({ review, className = "", dataUi, href, onOpen, socialDesktop = false, tabIndex }: ReviewPreviewCardProps) {
  const previewMedia = review.media[0];
  const mediaVariant = reviewMediaVariant(previewMedia);
  const classNames = ["review-card", socialDesktop ? "review-card--social-desktop" : "", className].filter(Boolean).join(" ");
  const sharedProps = {
    className: classNames,
    "data-review-id": review.id,
    "data-has-media": previewMedia ? "true" : "false",
    "data-media-variant": previewMedia ? mediaVariant : undefined,
    "data-ui": dataUi,
    tabIndex,
  };

  if (onOpen) {
    return (
      <SurfaceButton {...sharedProps} onClick={(event) => onOpen(review, event.currentTarget)}>
        <ReviewCardContent review={review} socialDesktop={socialDesktop} />
      </SurfaceButton>
    );
  }

  return (
    <a {...sharedProps} className={["ui-surface-button", classNames].join(" ")} href={href ?? `/reviews?review=${review.id}`}>
      <ReviewCardContent review={review} socialDesktop={socialDesktop} />
    </a>
  );
}
