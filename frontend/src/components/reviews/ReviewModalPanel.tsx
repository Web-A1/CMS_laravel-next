import ReviewMediaImage from "./ReviewMediaImage";
import { reviewContainedImageSizes } from "./reviewMediaVariants";
import type { CSSProperties, PointerEvent, RefObject } from "react";

import Button from "@/components/button/Button";
import ButtonArrow from "@/components/button/ButtonArrow";
import CloseButton from "@/components/button/CloseButton";
import PillStrip from "@/components/content/PillStrip";
import type { PublicReview, ReviewNavigation } from "@/lib/reviewsApi";
import { reviewDate, reviewMediaAlt, reviewTag, reviewTextParagraphs } from "./ReviewCard";
import ReviewModalSourcePanel from "./ReviewModalSourcePanel";
import ReviewAuthorAvatar from "./ReviewAuthorAvatar";
import { REVIEW_THUMB_IMAGE_SIZES } from "./reviewImageConfig";
import type { ReviewSubjectOption } from "./reviewSubjectOptions";
import type { ReviewModalMediaShape, ReviewTextScrollIndicator, ReviewTextScrollPosition } from "./reviewModalUtils";

interface ReviewModalPanelProps {
  readonly titleId: string;
  readonly textId: string;
  readonly closeButtonRef: RefObject<HTMLButtonElement | null>;
  readonly modalBodyRef: RefObject<HTMLDivElement | null>;
  readonly reviewCopyRef: RefObject<HTMLDivElement | null>;
  readonly reviewMetaRef: RefObject<HTMLDivElement | null>;
  readonly textScrollRef: RefObject<HTMLDivElement | null>;
  readonly review: PublicReview;
  readonly selectedMedia: PublicReview["media"][number] | null;
  readonly selectedMediaIndex: number | null;
  readonly selectedMediaMode: "screenshot-source" | "media-with-text" | "text-only";
  readonly selectedMediaShape: ReviewModalMediaShape | null;
  readonly textScrollIndicator: ReviewTextScrollIndicator;
  readonly textScrollPosition: ReviewTextScrollPosition;
  readonly isSelectedTextScrollable: boolean;
  readonly subjectOptions: ReadonlyArray<ReviewSubjectOption>;
  readonly activeModalSubject: string;
  readonly isReviewLoading: boolean;
  readonly reviewError: string | null;
  readonly onRetryReview: () => void;
  readonly reviewNavigation: ReviewNavigation | null;
  readonly selectedModalPosition: number;
  readonly selectedIndex: number | null;
  readonly selectedModalTotal: number;
  readonly modalReviewItemsCount: number;
  readonly canGoPrevious: boolean;
  readonly canGoNext: boolean;
  readonly hasModalReviewsForSubject: (subjectSlug: string) => boolean;
  readonly onClose: () => void;
  readonly onOpenImageViewer: (media: PublicReview["media"][number]) => void;
  readonly onSelectMedia: (mediaIndex: number) => void;
  readonly onTextScroll: () => void;
  readonly onSwipeStart: (event: PointerEvent<HTMLDivElement>) => void;
  readonly onSwipeCancel: () => void;
  readonly onSwipeFinish: (event: PointerEvent<HTMLDivElement>) => void;
  readonly onSelectModalSubject: (subjectSlug: string) => void;
  readonly onGoPrevious: () => void;
  readonly onGoNext: () => void;
}


export default function ReviewModalPanel({
  titleId,
  textId,
  closeButtonRef,
  modalBodyRef,
  reviewCopyRef,
  reviewMetaRef,
  textScrollRef,
  review,
  selectedMedia,
  selectedMediaIndex,
  selectedMediaMode,
  selectedMediaShape,
  textScrollIndicator,
  textScrollPosition,
  isSelectedTextScrollable,
  subjectOptions,
  activeModalSubject,
  isReviewLoading,
  reviewError,
  onRetryReview,
  reviewNavigation,
  selectedModalPosition,
  selectedIndex,
  selectedModalTotal,
  modalReviewItemsCount,
  canGoPrevious,
  canGoNext,
  hasModalReviewsForSubject,
  onClose,
  onOpenImageViewer,
  onSelectMedia,
  onTextScroll,
  onSwipeStart,
  onSwipeCancel,
  onSwipeFinish,
  onSelectModalSubject,
  onGoPrevious,
  onGoNext,
}: ReviewModalPanelProps) {
  const shouldShowSelectedText = review.text.trim().length > 0;
  const selectedReviewDate = reviewDate(review);
  const selectedReviewParagraphs = reviewTextParagraphs(review);

  return (
    <div
      className="review-modal__panel"
      data-has-media={selectedMedia ? "true" : "false"}
      data-media-mode={selectedMediaMode}
      data-media-shape={selectedMediaShape ?? undefined}
    >
      <div className="review-modal__header">
        <div className="review-modal__header-title">
          <h3 id={titleId}>{reviewTag(review)}</h3>
        </div>
        <CloseButton ref={closeButtonRef} aria-label="Закрыть отзыв" onClick={onClose} />
      </div>

      <div
        key={review.id}
        ref={modalBodyRef}
        className="review-modal__body"
        data-text-scrollable={isSelectedTextScrollable ? "true" : undefined}
        style={
          {
            "--review-modal-scroll-rail-height": `${textScrollIndicator.railHeight}px`,
            "--review-modal-scroll-rail-top": `${textScrollIndicator.railTop}px`,
          } as CSSProperties
        }
        onPointerDown={onSwipeStart}
        onPointerCancel={onSwipeCancel}
        onPointerUp={onSwipeFinish}
      >
        <span
          className="review-modal__scroll-rail"
          data-scrollable={isSelectedTextScrollable ? "true" : undefined}
          style={
            {
              "--review-modal-scroll-thumb-offset": `${textScrollIndicator.thumbOffset}%`,
              "--review-modal-scroll-thumb-size": `${textScrollIndicator.thumbSize}%`,
            } as CSSProperties
          }
          aria-hidden="true"
        />
        <div className="review-modal__visual">
          {selectedMedia ? (
            <div className="review-modal__media" aria-label="Скриншот отзыва">
              <button
                className="review-modal__image-trigger"
                type="button"
                onClick={() => onOpenImageViewer(selectedMedia)}
                aria-label="Открыть изображение крупно"
              >
                <ReviewMediaImage
                  role="display"
                  variants={selectedMedia.variants}
                  className="review-modal__image"
                  src={selectedMedia.url}
                  alt={reviewMediaAlt(selectedMedia, review)}
                  width={selectedMedia.width ?? 960}
                  height={selectedMedia.height ?? 960}
                  sizes={reviewContainedImageSizes(selectedMedia.width, selectedMedia.height, "display")}
                />
              </button>
              {review.media.length > 1 ? (
                <div className="review-modal__thumbs" aria-label="Изображения к отзыву">
                  {review.media.map((media, mediaIndex) => (
                    <button
                      key={media.id}
                      className="review-modal__thumb-trigger"
                      type="button"
                      onClick={() => onSelectMedia(mediaIndex)}
                      aria-label={`Показать изображение ${mediaIndex + 1}`}
                      aria-current={selectedMediaIndex === mediaIndex ? "true" : undefined}
                    >
                      <ReviewMediaImage
                        role="preview"
                        variants={media.variants}
                        className="review-modal__thumb"
                        src={media.url}
                        alt=""
                        width={media.width ?? 96}
                        height={media.height ?? 96}
                        sizes={REVIEW_THUMB_IMAGE_SIZES}
                      />
                    </button>
                  ))}
                </div>
              ) : null}
            </div>
          ) : null}
          <ReviewModalSourcePanel review={review} />
        </div>

        <div ref={reviewCopyRef} className="review-modal__copy">
          {shouldShowSelectedText ? (
            <div
              ref={textScrollRef}
              className="review-modal__text"
              onScroll={onTextScroll}
              data-scrollable={isSelectedTextScrollable ? "true" : undefined}
              data-scroll-position={isSelectedTextScrollable ? textScrollPosition : undefined}
            >
              <div id={textId} className="review-modal__paragraphs">
                {selectedReviewParagraphs.map((paragraph, paragraphIndex) => (
                  <p key={paragraphIndex}>{paragraph}</p>
                ))}
              </div>
            </div>
          ) : null}

          <div ref={reviewMetaRef} className="review-modal__meta-footer">
            <ReviewAuthorAvatar src={review.authorPhotoUrl} variants={review.authorPhotoVariants} />
            <p className="ui-meta-text review-modal__byline">
              <span className="review-modal__author">{review.displayName}</span>
              {selectedReviewDate ? (
                <time className="review-modal__date" dateTime={review.reviewWrittenAt ?? undefined}>
                  {selectedReviewDate}
                </time>
              ) : null}
            </p>
          </div>
        </div>
      </div>

      {subjectOptions.length > 0 ? (
        <PillStrip className="review-modal__subject-pills" ariaLabel="Предметы отзывов в модальном окне">
          {subjectOptions.map((subject) => {
            const isActive = subject.slug === activeModalSubject;
            const hasModalReviews =
              subject.slug === "" || subject.reviewsCount > 0 || hasModalReviewsForSubject(subject.slug);

            return (
              <button
                key={subject.slug || "all"}
                className="ui-pill-control review-modal__subject-pill"
                type="button"
                onClick={() => onSelectModalSubject(subject.slug)}
                aria-pressed={isActive}
                data-active={isActive ? "true" : undefined}
                data-ui={subject.slug ? `reviews.modal.subject.${subject.slug}` : "reviews.modal.subject.all"}
                disabled={isReviewLoading || !hasModalReviews}
              >
                <span className="ui-pill-control__label">{subject.title}</span>
                <span className="ui-pill-control__count">{subject.reviewsCount}</span>
              </button>
            );
          })}
        </PillStrip>
      ) : null}

      {reviewError ? <div className="px-4 text-sm ui-text-secondary" role="alert" data-ui="reviews.modal.error"><p>{reviewError}</p><Button type="button" variant="ghost" size="sm" fullWidth={false} disabled={isReviewLoading} onClick={onRetryReview} data-ui="reviews.modal.retry">Повторить</Button></div> : null}
      <div className="review-modal__controls" aria-label="Навигация по отзывам" aria-busy={isReviewLoading}>
        <Button variant="ghost" iconOnly fullWidth={false}
          className="review-modal__nav"
          type="button"
          onClick={onGoPrevious}
          disabled={!canGoPrevious}
          aria-label="Предыдущий отзыв"
        >
          <ButtonArrow direction="left" />
        </Button>

        <span className="review-modal__nav-status" aria-live="polite">
          {reviewNavigation?.position ?? (selectedModalPosition >= 0 ? selectedModalPosition + 1 : (selectedIndex ?? 0) + 1)}{" "}
          / {selectedModalTotal > 0 ? selectedModalTotal : modalReviewItemsCount}
        </span>

        <Button variant="ghost" iconOnly fullWidth={false}
          className="review-modal__nav"
          type="button"
          onClick={onGoNext}
          disabled={!canGoNext}
          aria-label="Следующий отзыв"
        >
          <ButtonArrow direction="right" />
        </Button>
      </div>
    </div>
  );
}
