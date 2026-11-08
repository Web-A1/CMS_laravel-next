"use client";

import { useEffect, useState } from "react";

import type { ReviewsCollectionProps } from "@/components/reviews/ReviewsCollection";
import type { PublicReview } from "@/lib/reviewsApi";
import { mountHomeReviewsMarquee } from "./homeReviewsMarquee";

type ReviewsCollectionComponent = typeof import("@/components/reviews/ReviewsCollection").default;

type HomeReviewsLazyInteractivityProps = {
  readonly reviews: ReadonlyArray<PublicReview>;
  readonly previewId: string;
  readonly hasMarquee: boolean;
};

export default function HomeReviewsLazyInteractivity({ reviews, previewId, hasMarquee }: HomeReviewsLazyInteractivityProps) {
  const [shouldLoad, setShouldLoad] = useState(false);
  const [InteractiveReviews, setInteractiveReviews] = useState<ReviewsCollectionComponent | null>(null);

  useEffect(() => {
    if (reviews.length === 0 || shouldLoad) {
      return;
    }

    const previewElement = document.getElementById(previewId);
    const sectionElement = previewElement?.closest<HTMLElement>("[data-ui='home.reviews']");

    if (!previewElement || !sectionElement) {
      globalThis.setTimeout(() => setShouldLoad(true), 0);
      return;
    }

    const loadInteractivity = () => setShouldLoad(true);

    if (!("IntersectionObserver" in window)) {
      globalThis.setTimeout(loadInteractivity, 0);
      return;
    }

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry?.isIntersecting) {
          loadInteractivity();
        }
      },
      { rootMargin: "260px 0px" },
    );

    observer.observe(sectionElement);
    previewElement.addEventListener("pointerenter", loadInteractivity, { once: true });
    previewElement.addEventListener("focusin", loadInteractivity, { once: true });

    return () => {
      observer.disconnect();
      previewElement.removeEventListener("pointerenter", loadInteractivity);
      previewElement.removeEventListener("focusin", loadInteractivity);
    };
  }, [previewId, reviews.length, shouldLoad]);

  useEffect(() => {
    if (!shouldLoad || InteractiveReviews || reviews.length === 0) {
      return;
    }

    let isCancelled = false;

    void import("@/components/reviews/ReviewsCollection")
      .then((module) => {
        if (!isCancelled) {
          setInteractiveReviews(() => module.default);
        }
      })
      .catch(() => {
        // Keep the server-rendered links usable when the optional chunk fails.
      });

    return () => {
      isCancelled = true;
    };
  }, [InteractiveReviews, reviews.length, shouldLoad]);

  useEffect(() => {
    const previewElement = document.getElementById(previewId);

    if (!previewElement || !InteractiveReviews) {
      return;
    }

    const interactiveElement = previewElement.closest("section")?.querySelector<HTMLElement>(".home-reviews-interactive-track");
    if (interactiveElement) {
      interactiveElement.scrollLeft = previewElement.scrollLeft;
      const focusedPreview = previewElement.contains(document.activeElement) ? document.activeElement : null;
      const reviewId = focusedPreview?.closest("[data-review-id]")?.getAttribute("data-review-id");
      if (reviewId) {
        interactiveElement.querySelector<HTMLElement>(`[data-review-id="${CSS.escape(reviewId)}"]`)?.focus({ preventScroll: true });
      } else if (focusedPreview) {
        interactiveElement.focus({ preventScroll: true });
      }
    }

    previewElement.hidden = true;
    const viewport = interactiveElement?.closest<HTMLElement>(".home-reviews__viewport");
    const stopMarquee = hasMarquee && viewport && interactiveElement
      ? mountHomeReviewsMarquee(viewport, interactiveElement)
      : undefined;

    return () => {
      stopMarquee?.();
      previewElement.hidden = false;
    };
  }, [InteractiveReviews, hasMarquee, previewId]);

  if (!InteractiveReviews) {
    return null;
  }

  const interactiveProps: ReviewsCollectionProps = {
    reviews,
    listClassName: "home-reviews-track home-reviews-interactive-track",
    dataUiPrefix: "home.reviews",
    socialDesktop: hasMarquee,
    horizontalScroll: true,
    emptyMessage: "Отзывы появятся после публикации первых проверенных материалов.",
  };

  return <InteractiveReviews {...interactiveProps} />;
}
