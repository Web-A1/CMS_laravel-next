"use client";

import type { PointerEvent } from "react";
import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState, useSyncExternalStore } from "react";
import { createPortal } from "react-dom";
import { useRouter } from "next/navigation";

import HorizontalScrollRegion from "@/components/accessibility/HorizontalScrollRegion";
import type { PublicReview, ReviewNavigation } from "@/lib/reviewsApi";
import ReviewImageViewer from "./ReviewImageViewer";
import ReviewModalPanel from "./ReviewModalPanel";
import ReviewPreviewCard from "./ReviewCard";
import type { ReviewSubjectOption } from "./reviewSubjectOptions";
import { useReviewModalController } from "./useReviewModalController";
import { useReviewTextScroll } from "./useReviewTextScroll";
import "./reviews.css";

export type ReviewsCollectionProps = {
  readonly reviews: ReadonlyArray<PublicReview>;
  readonly modalReviews?: ReadonlyArray<PublicReview>;
  readonly initialReview?: PublicReview | null;
  readonly initialReviewNavigation?: ReviewNavigation | null;
  readonly subjectOptions?: ReadonlyArray<ReviewSubjectOption>;
  readonly initialReviewId?: number | null;
  readonly initialSubject?: string;
  readonly initialSearch?: string;
  readonly initialPerPage?: number;
  readonly initialPage?: number;
  readonly listClassName?: string;
  readonly cardClassName?: string;
  readonly socialDesktop?: boolean;
  readonly horizontalScroll?: boolean;
  readonly dataUiPrefix: string;
  readonly emptyMessage?: string;
};

function subscribeToHydrationStore(): () => void {
  return () => undefined;
}

function getHydratedClientSnapshot(): boolean {
  return true;
}

function getHydratedServerSnapshot(): boolean {
  return false;
}

export default function ReviewsCollection({
  reviews,
  modalReviews,
  initialReview = null,
  initialReviewNavigation = null,
  subjectOptions = [],
  initialReviewId = null,
  initialSubject = "",
  initialSearch = "",
  initialPerPage = 25,
  initialPage = 1,
  listClassName = "",
  cardClassName = "",
  socialDesktop = false,
  horizontalScroll = false,
  dataUiPrefix,
  emptyMessage = "По выбранным фильтрам пока нет опубликованных отзывов.",
}: ReviewsCollectionProps) {
  const router = useRouter();
  const modalReviewItems = modalReviews ?? reviews;
  const [viewerMedia, setViewerMedia] = useState<PublicReview["media"][number] | null>(null);
  const closeImageViewer = useCallback(() => setViewerMedia(null), []);
  const openImageViewer = useCallback((media: PublicReview["media"][number]) => setViewerMedia(media), []);
  const hasHydrated = useSyncExternalStore(
    subscribeToHydrationStore,
    getHydratedClientSnapshot,
    getHydratedServerSnapshot,
  );
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  const modalRef = useRef<HTMLDivElement>(null);
  const viewerWasOpen = useRef(false);
  const swipeStartRef = useRef<{ readonly x: number; readonly y: number; readonly pointerId: number } | null>(null);
  const titleId = useId();
  const textId = useId();
  const refreshReviewsSelection = useCallback(() => {
    const params = new URL(window.location.href).searchParams;
    if (window.location.pathname === "/reviews" && ((params.get("subject") ?? "") !== initialSubject || (params.get("q") ?? "").trim().slice(0, 200) !== initialSearch || Number(params.get("per_page") ?? 25) !== initialPerPage || Number(params.get("page") ?? 1) !== initialPage)) {
      router.refresh();
    }
  }, [initialPage, initialPerPage, initialSearch, initialSubject, router]);
  const {
    activeModalSubject,
    selectedIndex,
    selectedReview,
    selectedMedia,
    selectedMediaIndex,
    selectedMediaMode,
    selectedMediaShape,
    selectedModalPosition,
    selectedModalTotal,
    reviewNavigation,
    isReviewLoading,
    reviewError,
    retryReview,
    canGoPrevious,
    canGoNext,
    closeSelectedReview: dismissSelectedReview,
    openReview,
    goPrevious,
    goNext,
    selectModalSubject,
    selectMedia,
    hasModalReviewsForSubject,
  } = useReviewModalController({
    modalReviewItems,
    initialReview,
    initialReviewNavigation,
    initialReviewId,
    initialSubject,
    initialSearch,
    initialPerPage,
    onHistoryClose: refreshReviewsSelection,
    closeImageViewer,
  });
  const closeSelectedReview = useCallback(() => {
    dismissSelectedReview();
    refreshReviewsSelection();
  }, [dismissSelectedReview, refreshReviewsSelection]);
  const shouldShowSelectedText = selectedReview ? selectedReview.text.trim().length > 0 : false;
  const {
    modalBodyRef,
    reviewCopyRef,
    reviewMetaRef,
    textScrollRef,
    textScrollPosition,
    textScrollIndicator,
    isSelectedTextScrollable,
    updateTextScrollState,
  } = useReviewTextScroll({
    selectedReviewId: selectedReview?.id ?? null,
    selectedMediaIndex,
    shouldShowSelectedText,
  });
  const portalRoot = hasHydrated ? document.body : null;
  const ReviewList = horizontalScroll ? HorizontalScrollRegion : "div";

  useEffect(() => {
    const modalOpen = selectedReview !== null;
    document.body.classList.toggle("reviews-modal-scroll-lock", modalOpen);
    return () => document.body.classList.remove("reviews-modal-scroll-lock");
  }, [selectedReview]);

  const startSwipe = useCallback((event: PointerEvent<HTMLDivElement>) => {
    if (event.pointerType === "mouse") {
      return;
    }

    swipeStartRef.current = {
      x: event.clientX,
      y: event.clientY,
      pointerId: event.pointerId,
    };
  }, []);

  const cancelSwipe = useCallback(() => {
    swipeStartRef.current = null;
  }, []);

  const finishSwipe = useCallback(
    (event: PointerEvent<HTMLDivElement>) => {
      const start = swipeStartRef.current;
      swipeStartRef.current = null;

      if (!start || start.pointerId !== event.pointerId) {
        return;
      }

      const deltaX = event.clientX - start.x;
      const deltaY = event.clientY - start.y;
      const isHorizontalSwipe = Math.abs(deltaX) >= 52 && Math.abs(deltaX) > Math.abs(deltaY) * 1.35;

      if (!isHorizontalSwipe) {
        return;
      }

      if (deltaX < 0) {
        goNext();
      } else {
        goPrevious();
      }
    },
    [goNext, goPrevious],
  );

  useEffect(() => {
    if (selectedReview) closeButtonRef.current?.focus();
  }, [selectedReview]);

  useLayoutEffect(() => {
    // The review panel unmounts behind the viewer, so its original trigger is replaced.
    if (viewerWasOpen.current && !viewerMedia) {
      modalRef.current?.querySelector<HTMLButtonElement>(".review-modal__image-trigger")?.focus({ preventScroll: true });
    }
    viewerWasOpen.current = viewerMedia !== null;
  }, [viewerMedia]);

  useEffect(() => {
    if (!selectedReview) {
      return;
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (viewerMedia) {
        return;
      }

      if (event.key === "Escape") {
        closeSelectedReview();
        return;
      }

      if (event.key === "ArrowLeft") {
        event.preventDefault();
        goPrevious();
        return;
      }

      if (event.key === "ArrowRight") {
        event.preventDefault();
        goNext();
        return;
      }

      if (event.key !== "Tab") {
        return;
      }

      if (!modalRef.current) {
        return;
      }

      const focusableElements = Array.from(
        modalRef.current.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'),
      );
      if (!focusableElements.length) {
        event.preventDefault();
        return;
      }

      const firstElement = focusableElements[0];
      const lastElement = focusableElements[focusableElements.length - 1];
      const activeElement = document.activeElement;

      if (event.shiftKey && activeElement === firstElement) {
        event.preventDefault();
        lastElement.focus();
      } else if (!event.shiftKey && activeElement === lastElement) {
        event.preventDefault();
        firstElement.focus();
      } else if (!modalRef.current.contains(activeElement)) {
        event.preventDefault();
        firstElement.focus();
      }
    };

    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [
    closeSelectedReview,
    goNext,
    goPrevious,
    selectedReview,
    viewerMedia,
  ]);

  if (reviews.length === 0) {
    return (
      <p className="ui-card-text" data-ui={`${dataUiPrefix}.empty`}>
        {emptyMessage}
      </p>
    );
  }

  return (
    <>
      <ReviewList className={listClassName} role={horizontalScroll ? "region" : undefined} tabIndex={horizontalScroll ? 0 : undefined} aria-label="Отзывы студентов" data-ui={`${dataUiPrefix}.list`}>
        <div className="review-list-group">
          {reviews.map((review) => (
            <ReviewPreviewCard
              key={review.id}
              review={review}
              className={cardClassName}
              socialDesktop={socialDesktop}
              dataUi={`${dataUiPrefix}.card`}
              onOpen={openReview}
            />
          ))}
        </div>
      </ReviewList>

      {portalRoot && selectedReview
        ? createPortal(
            <div
              ref={modalRef}
              className="review-modal"
              data-ui={`${dataUiPrefix}.modal`}
              data-review-id={selectedReview.id}
              role={viewerMedia ? undefined : "dialog"}
              aria-modal={viewerMedia ? undefined : "true"}
              aria-labelledby={titleId}
              aria-describedby={shouldShowSelectedText ? textId : undefined}
              data-has-media={selectedMedia ? "true" : "false"}
              data-viewer-open={viewerMedia ? "true" : undefined}
              onMouseDown={(event) => {
                if (event.target === event.currentTarget) {
                  closeSelectedReview();
                }
              }}
            >
              {!viewerMedia ? (
                <ReviewModalPanel
                  titleId={titleId}
                  textId={textId}
                  closeButtonRef={closeButtonRef}
                  modalBodyRef={modalBodyRef}
                  reviewCopyRef={reviewCopyRef}
                  reviewMetaRef={reviewMetaRef}
                  textScrollRef={textScrollRef}
                  review={selectedReview}
                  selectedMedia={selectedMedia}
                  selectedMediaIndex={selectedMediaIndex}
                  selectedMediaMode={selectedMediaMode}
                  selectedMediaShape={selectedMediaShape}
                  textScrollIndicator={textScrollIndicator}
                  textScrollPosition={textScrollPosition}
                  isSelectedTextScrollable={isSelectedTextScrollable}
                  subjectOptions={subjectOptions}
                  activeModalSubject={activeModalSubject}
                  isReviewLoading={isReviewLoading}
                  reviewError={reviewError}
                  onRetryReview={retryReview}
                  reviewNavigation={reviewNavigation}
                  selectedModalPosition={selectedModalPosition}
                  selectedIndex={selectedIndex}
                  selectedModalTotal={selectedModalTotal}
                  modalReviewItemsCount={modalReviewItems.length}
                  canGoPrevious={canGoPrevious}
                  canGoNext={canGoNext}
                  hasModalReviewsForSubject={hasModalReviewsForSubject}
                  onClose={closeSelectedReview}
                  onOpenImageViewer={openImageViewer}
                  onSelectMedia={selectMedia}
                  onTextScroll={updateTextScrollState}
                  onSwipeStart={startSwipe}
                  onSwipeCancel={cancelSwipe}
                  onSwipeFinish={finishSwipe}
                  onSelectModalSubject={selectModalSubject}
                  onGoPrevious={goPrevious}
                  onGoNext={goNext}
                />
              ) : null}

              {viewerMedia ? (
                <ReviewImageViewer
                  media={viewerMedia}
                  review={selectedReview}
                  onClose={closeImageViewer}
                />
              ) : null}
            </div>,
            portalRoot,
          )
        : null}
    </>
  );
}
