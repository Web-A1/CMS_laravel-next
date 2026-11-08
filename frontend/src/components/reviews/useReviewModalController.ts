"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import { getReviewResult, listReviewsResult, type PublicReview, type ReviewNavigation } from "@/lib/reviewsApi";
import { isScreenshotSourceMedia, reviewModalMediaShape, reviewSubjectSlug } from "./reviewModalUtils";

type SelectedMediaMode = "screenshot-source" | "media-with-text" | "text-only";
type RetryReview = { readonly kind: "detail"; readonly id: number; readonly subject: string; readonly syncUrl: boolean }
  | { readonly kind: "subject"; readonly subject: string; readonly syncUrl: boolean };

interface UseReviewModalControllerOptions {
  readonly modalReviewItems: ReadonlyArray<PublicReview>;
  readonly initialReview: PublicReview | null;
  readonly initialReviewNavigation: ReviewNavigation | null;
  readonly initialReviewId: number | null;
  readonly initialSubject?: string;
  readonly initialSearch?: string;
  readonly initialPerPage?: number;
  readonly onHistoryClose?: () => void;
  readonly closeImageViewer: () => void;
}

export function useReviewModalController({
  modalReviewItems,
  initialReview,
  initialReviewNavigation,
  initialReviewId,
  initialSubject = "",
  initialSearch = "",
  initialPerPage = 25,
  onHistoryClose,
  closeImageViewer,
}: UseReviewModalControllerOptions) {
  const initialSelectedIndex =
    initialReviewId === null ? -1 : modalReviewItems.findIndex((item) => item.id === initialReviewId);
  const initialModalSubject = initialReviewNavigation?.filters.subject ?? initialSubject;
  const [selectedIndex, setSelectedIndex] = useState<number | null>(initialSelectedIndex >= 0 ? initialSelectedIndex : null);
  const [selectedReviewOverride, setSelectedReviewOverride] = useState<PublicReview | null>(
    initialSelectedIndex >= 0 ? null : initialReview,
  );
  const [reviewNavigation, setReviewNavigation] = useState<ReviewNavigation | null>(initialReviewNavigation);
  const [isReviewLoading, setIsReviewLoading] = useState(false);
  const [reviewError, setReviewError] = useState<string | null>(null);
  const [activeModalSubject, setActiveModalSubject] = useState(initialModalSubject);
  const [selectedMediaIndex, setSelectedMediaIndex] = useState<number | null>(null);
  const triggerButtonRef = useRef<HTMLButtonElement | null>(null);
  const requestSequenceRef = useRef(0);
  const retryRef = useRef<RetryReview | null>(null);

  useEffect(() => () => {
    requestSequenceRef.current += 1;
  }, []);

  const selectedReview = selectedReviewOverride ?? (selectedIndex === null ? null : modalReviewItems[selectedIndex] ?? null);
  const activeModalReviewIndexes =
    activeModalSubject === ""
      ? modalReviewItems.map((_, reviewIndex) => reviewIndex)
      : modalReviewItems.flatMap((review, reviewIndex) =>
          reviewSubjectSlug(review) === activeModalSubject ? [reviewIndex] : [],
        );
  const selectedModalPosition = selectedIndex === null ? -1 : activeModalReviewIndexes.indexOf(selectedIndex);
  const selectedModalTotal = reviewNavigation?.total ?? activeModalReviewIndexes.length;
  const selectedMedia = selectedReview?.media[selectedMediaIndex ?? 0] ?? null;
  const selectedMediaMode: SelectedMediaMode = selectedMedia
    ? (isScreenshotSourceMedia(selectedMedia) ? "screenshot-source" : "media-with-text")
    : "text-only";
  const selectedMediaShape = reviewModalMediaShape(selectedMedia);
  const canGoPrevious = !isReviewLoading && !reviewError && (reviewNavigation ? reviewNavigation.previousId !== null : selectedModalPosition > 0);
  const canGoNext =
    !isReviewLoading && !reviewError &&
    (reviewNavigation ? reviewNavigation.nextId !== null : selectedModalPosition >= 0 && selectedModalPosition < selectedModalTotal - 1);

  const syncReviewUrl = useCallback((reviewId: number | null, subjectSlug: string) => {
    const url = new URL(window.location.href);
    if ((url.searchParams.get("subject") ?? "") !== subjectSlug) url.searchParams.delete("page");

    if (reviewId === null) {
      url.searchParams.delete("review");
    } else {
      url.searchParams.set("review", String(reviewId));
    }
    if (subjectSlug) url.searchParams.set("subject", subjectSlug);
    else url.searchParams.delete("subject");
    const nextUrl = `${url.pathname}${url.search}${url.hash}`;
    if (nextUrl !== `${window.location.pathname}${window.location.search}${window.location.hash}`) {
      window.history.pushState(null, "", nextUrl);
    }
  }, []);

  const dismissSelectedReview = useCallback(() => {
    requestSequenceRef.current += 1;
    setSelectedMediaIndex(null);
    setSelectedReviewOverride(null);
    setReviewNavigation(null);
    setIsReviewLoading(false);
    setReviewError(null);
    retryRef.current = null;
    closeImageViewer();
    setSelectedIndex(null);
    window.requestAnimationFrame(() => triggerButtonRef.current?.focus());
  }, [closeImageViewer]);

  const closeSelectedReview = useCallback(() => {
    dismissSelectedReview();
    syncReviewUrl(null, activeModalSubject);
  }, [activeModalSubject, dismissSelectedReview, syncReviewUrl]);

  const loadReviewById = useCallback(
    async (reviewId: number, shouldSyncUrl = true, subjectSlug = activeModalSubject) => {
      const requestSequence = ++requestSequenceRef.current;
      setIsReviewLoading(true);
      setReviewError(null);
      retryRef.current = { kind: "detail", id: reviewId, subject: subjectSlug, syncUrl: shouldSyncUrl };

      try {
        const nextReview = await getReviewResult(reviewId, { subject: subjectSlug || undefined, search: initialSearch || undefined });

        if (requestSequence !== requestSequenceRef.current) {
          return;
        }

        if (!nextReview.data) {
          setReviewError("Этот отзыв недоступен в выбранной подборке. Повторите попытку или выберите другой предмет.");
          setIsReviewLoading(false);
          return;
        }

        if (nextReview.navigation && (nextReview.navigation.filters.subject ?? "") !== subjectSlug) {
          throw new Error("Review selection mismatch");
        }

        closeImageViewer();
        setSelectedReviewOverride(nextReview.data);
        setReviewNavigation(nextReview.navigation);
        setActiveModalSubject(subjectSlug);
        setSelectedIndex(null);
        setSelectedMediaIndex(nextReview.data.media.length ? 0 : null);
        setIsReviewLoading(false);
        retryRef.current = null;

        if (shouldSyncUrl) {
          syncReviewUrl(nextReview.data.id, subjectSlug);
        }
      } catch {
        if (requestSequence === requestSequenceRef.current) {
          setReviewError("Не удалось загрузить отзыв. Проверьте соединение и повторите попытку.");
          setIsReviewLoading(false);
        }
      }
    },
    [activeModalSubject, closeImageViewer, initialSearch, syncReviewUrl],
  );

  const selectReviewIndex = useCallback(
    (reviewIndex: number, shouldSyncUrl = true, subjectSlug = activeModalSubject) => {
      requestSequenceRef.current += 1;
      setIsReviewLoading(false);
      setReviewError(null);
      retryRef.current = null;
      const nextReview = modalReviewItems[reviewIndex];
      closeImageViewer();
      setSelectedReviewOverride(null);
      setReviewNavigation(null);
      setSelectedIndex(reviewIndex);
      setSelectedMediaIndex(nextReview?.media.length ? 0 : null);
      if (nextReview && shouldSyncUrl) {
        syncReviewUrl(nextReview.id, subjectSlug);
      }
    },
    [activeModalSubject, closeImageViewer, modalReviewItems, syncReviewUrl],
  );

  const openReview = useCallback(
    (review: PublicReview, trigger: HTMLButtonElement) => {
      const reviewIndex = modalReviewItems.findIndex((item) => item.id === review.id);
      if (reviewIndex < 0) {
        return;
      }

      triggerButtonRef.current = trigger;
      const subjectSlug = initialSubject;
      setActiveModalSubject(subjectSlug);
      selectReviewIndex(reviewIndex, true, subjectSlug);
      void loadReviewById(review.id, true, subjectSlug);
    },
    [initialSubject, loadReviewById, modalReviewItems, selectReviewIndex],
  );

  const goPrevious = useCallback(() => {
    if (!canGoPrevious) return;
    if (reviewNavigation?.previousId) {
      void loadReviewById(reviewNavigation.previousId);
      return;
    }

    if (!reviewNavigation && selectedModalPosition > 0) {
      selectReviewIndex(activeModalReviewIndexes[selectedModalPosition - 1]);
    }
  }, [activeModalReviewIndexes, canGoPrevious, loadReviewById, reviewNavigation, selectedModalPosition, selectReviewIndex]);

  const goNext = useCallback(() => {
    if (!canGoNext) return;
    if (reviewNavigation?.nextId) {
      void loadReviewById(reviewNavigation.nextId);
      return;
    }

    if (!reviewNavigation && selectedModalPosition >= 0 && selectedModalPosition < activeModalReviewIndexes.length - 1) {
      selectReviewIndex(activeModalReviewIndexes[selectedModalPosition + 1]);
    }
  }, [activeModalReviewIndexes, canGoNext, loadReviewById, reviewNavigation, selectedModalPosition, selectReviewIndex]);

  const selectModalSubject = useCallback(
    async (subjectSlug: string, shouldSyncUrl = true) => {
      if (selectedReview && (subjectSlug === "" || reviewSubjectSlug(selectedReview) === subjectSlug)) {
        await loadReviewById(selectedReview.id, shouldSyncUrl, subjectSlug);
        return;
      }

      setIsReviewLoading(true);
      setReviewError(null);
      retryRef.current = { kind: "subject", subject: subjectSlug, syncUrl: shouldSyncUrl };
      const requestSequence = ++requestSequenceRef.current;

      try {
        const nextReviews = await listReviewsResult({
          subject: subjectSlug || undefined,
          search: initialSearch || undefined,
          perPage: 1,
        });
        if (requestSequence !== requestSequenceRef.current) {
          return;
        }
        const nextReview = nextReviews.data[0] ?? null;

        if (!nextReview) {
          setReviewError(initialSearch
            ? "По вашему запросу в этом предмете отзывов не найдено. Выберите другой предмет."
            : "По этому предмету пока нет опубликованных отзывов. Выберите другой предмет.");
          setIsReviewLoading(false);
          return;
        }

        await loadReviewById(nextReview.id, shouldSyncUrl, subjectSlug);
      } catch {
        if (requestSequence === requestSequenceRef.current) {
          setReviewError("Не удалось загрузить отзывы. Проверьте соединение и повторите попытку.");
          setIsReviewLoading(false);
        }
      }
    },
    [initialSearch, loadReviewById, selectedReview],
  );

  useEffect(() => {
    const onPopState = () => {
      const params = new URL(window.location.href).searchParams;
      if ((params.get("q") ?? "").trim().slice(0, 200) !== initialSearch || Number(params.get("per_page") ?? 25) !== initialPerPage) {
        dismissSelectedReview();
        onHistoryClose?.();
        return;
      }
      const id = Number(params.get("review"));
      if (!Number.isSafeInteger(id) || id <= 0) {
        dismissSelectedReview();
        onHistoryClose?.();
        return;
      }
      void loadReviewById(id, false, params.get("subject") ?? "");
    };
    window.addEventListener("popstate", onPopState);
    return () => window.removeEventListener("popstate", onPopState);
  }, [dismissSelectedReview, initialPerPage, initialSearch, loadReviewById, onHistoryClose]);

  const retryReview = useCallback(() => {
    const retry = retryRef.current;
    if (retry?.kind === "detail") void loadReviewById(retry.id, retry.syncUrl, retry.subject);
    else if (retry?.kind === "subject") void selectModalSubject(retry.subject, retry.syncUrl);
  }, [loadReviewById, selectModalSubject]);

  const selectMedia = useCallback((mediaIndex: number) => {
    closeImageViewer();
    setSelectedMediaIndex(mediaIndex);
  }, [closeImageViewer]);

  const hasModalReviewsForSubject = useCallback(
    (subjectSlug: string) => subjectSlug === "" || modalReviewItems.some((review) => reviewSubjectSlug(review) === subjectSlug),
    [modalReviewItems],
  );

  return {
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
    closeSelectedReview,
    openReview,
    goPrevious,
    goNext,
    selectModalSubject,
    selectMedia,
    hasModalReviewsForSubject,
  };
}
