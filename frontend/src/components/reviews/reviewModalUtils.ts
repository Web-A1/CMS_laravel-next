import type { PublicReview } from "@/lib/reviewsApi";

export type ReviewModalMediaShape = "portrait" | "square" | "landscape";
export type ReviewTextScrollPosition = "none" | "top" | "middle" | "bottom";

export interface ReviewTextScrollIndicator {
  readonly railHeight: number;
  readonly railTop: number;
  readonly thumbOffset: number;
  readonly thumbSize: number;
}

export const REVIEW_SCROLL_THUMB_MIN_SIZE = 18;
export const REVIEW_SCROLL_RAIL_VERTICAL_INSET = 2;
export const REVIEW_SCROLL_SIGNIFICANT_OVERFLOW = 8;

export function clampPercentage(value: number): number {
  return Math.min(100, Math.max(0, value));
}

export function reviewSubjectSlug(review: PublicReview | null | undefined): string {
  return review?.subject?.slug ?? "";
}

export function isScreenshotSourceMedia(media: PublicReview["media"][number] | null): boolean {
  return media?.type === "screenshot" || media?.type === "legacy_screenshot";
}

export function reviewModalMediaShape(media: PublicReview["media"][number] | null): ReviewModalMediaShape | null {
  if (!media?.width || !media.height) {
    return null;
  }

  const ratio = media.width / media.height;

  if (ratio >= 1.15) {
    return "landscape";
  }

  if (ratio >= 0.85) {
    return "square";
  }

  return "portrait";
}
