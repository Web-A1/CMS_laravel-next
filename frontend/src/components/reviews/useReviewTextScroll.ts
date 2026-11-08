"use client";

import { useCallback, useLayoutEffect, useRef, useState } from "react";

import {
  clampPercentage,
  REVIEW_SCROLL_RAIL_VERTICAL_INSET,
  REVIEW_SCROLL_SIGNIFICANT_OVERFLOW,
  REVIEW_SCROLL_THUMB_MIN_SIZE,
  type ReviewTextScrollIndicator,
  type ReviewTextScrollPosition,
} from "./reviewModalUtils";

const RESET_SCROLL_INDICATOR: ReviewTextScrollIndicator = {
  railHeight: 0,
  railTop: 0,
  thumbOffset: 0,
  thumbSize: 100,
};

interface UseReviewTextScrollOptions {
  readonly selectedReviewId: number | null;
  readonly selectedMediaIndex: number | null;
  readonly shouldShowSelectedText: boolean;
}

export function useReviewTextScroll({
  selectedReviewId,
  selectedMediaIndex,
  shouldShowSelectedText,
}: UseReviewTextScrollOptions) {
  const [textScrollPosition, setTextScrollPosition] = useState<ReviewTextScrollPosition>("none");
  const [textScrollIndicator, setTextScrollIndicator] = useState<ReviewTextScrollIndicator>(RESET_SCROLL_INDICATOR);
  const modalBodyRef = useRef<HTMLDivElement>(null);
  const reviewCopyRef = useRef<HTMLDivElement>(null);
  const reviewMetaRef = useRef<HTMLDivElement>(null);
  const textScrollRef = useRef<HTMLDivElement>(null);

  const updateTextScrollState = useCallback(() => {
    const element = textScrollRef.current;
    const bodyElement = modalBodyRef.current;
    const copyElement = reviewCopyRef.current;
    const metaElement = reviewMetaRef.current;

    if (!element || !shouldShowSelectedText) {
      setTextScrollPosition("none");
      setTextScrollIndicator(RESET_SCROLL_INDICATOR);
      return;
    }

    const bodyRect = bodyElement?.getBoundingClientRect();
    const copyRect = copyElement?.getBoundingClientRect();
    const railTop = (bodyRect && copyRect ? Math.max(0, copyRect.top - bodyRect.top) : 0) + REVIEW_SCROLL_RAIL_VERTICAL_INSET;
    const railHeight = Math.max(
      0,
      (copyElement?.clientHeight ?? element.clientHeight) -
        (metaElement?.getBoundingClientRect().height ?? 0) -
        REVIEW_SCROLL_RAIL_VERTICAL_INSET * 2,
    );
    const scrollOverflow = element.scrollHeight - element.clientHeight;

    if (scrollOverflow < REVIEW_SCROLL_SIGNIFICANT_OVERFLOW) {
      setTextScrollPosition("none");
      setTextScrollIndicator((currentIndicator) =>
        Math.abs(currentIndicator.railHeight - railHeight) < 0.5 &&
        Math.abs(currentIndicator.railTop - railTop) < 0.5 &&
        currentIndicator.thumbOffset === 0 &&
        currentIndicator.thumbSize === 100
          ? currentIndicator
          : { railHeight, railTop, thumbOffset: 0, thumbSize: 100 },
      );
      return;
    }

    const isAtTop = element.scrollTop <= 1;
    const isAtBottom = element.scrollTop + element.clientHeight >= element.scrollHeight - 1;
    const nextPosition: ReviewTextScrollPosition = isAtTop ? "top" : isAtBottom ? "bottom" : "middle";
    const scrollRange = scrollOverflow;
    const thumbSize = clampPercentage(
      Math.max(REVIEW_SCROLL_THUMB_MIN_SIZE, (element.clientHeight / element.scrollHeight) * 100),
    );
    const thumbOffset = clampPercentage((element.scrollTop / scrollRange) * (100 - thumbSize));

    setTextScrollPosition((currentPosition) => (currentPosition === nextPosition ? currentPosition : nextPosition));
    setTextScrollIndicator((currentIndicator) =>
      Math.abs(currentIndicator.railHeight - railHeight) < 0.5 &&
      Math.abs(currentIndicator.railTop - railTop) < 0.5 &&
      Math.abs(currentIndicator.thumbOffset - thumbOffset) < 0.1 &&
      Math.abs(currentIndicator.thumbSize - thumbSize) < 0.1
        ? currentIndicator
        : { railHeight, railTop, thumbOffset, thumbSize },
    );
  }, [shouldShowSelectedText]);

  useLayoutEffect(() => {
    if (!selectedReviewId || !shouldShowSelectedText) {
      return undefined;
    }

    let isActive = true;
    let animationFrame = 0;

    const scheduleTextScrollUpdate = () => {
      if (!isActive) {
        return;
      }

      window.cancelAnimationFrame(animationFrame);
      animationFrame = window.requestAnimationFrame(updateTextScrollState);
    };

    updateTextScrollState();
    scheduleTextScrollUpdate();
    window.addEventListener("resize", scheduleTextScrollUpdate);

    const resizeObserver = new ResizeObserver(scheduleTextScrollUpdate);
    [modalBodyRef.current, reviewCopyRef.current, reviewMetaRef.current, textScrollRef.current].forEach((element) => {
      if (element) {
        resizeObserver.observe(element);
      }
    });

    void document.fonts.ready.then(scheduleTextScrollUpdate);

    return () => {
      isActive = false;
      window.cancelAnimationFrame(animationFrame);
      window.removeEventListener("resize", scheduleTextScrollUpdate);
      resizeObserver.disconnect();
    };
  }, [selectedReviewId, selectedMediaIndex, shouldShowSelectedText, updateTextScrollState]);

  return {
    modalBodyRef,
    reviewCopyRef,
    reviewMetaRef,
    textScrollRef,
    textScrollPosition,
    textScrollIndicator,
    isSelectedTextScrollable: textScrollPosition !== "none",
    updateTextScrollState,
  };
}
