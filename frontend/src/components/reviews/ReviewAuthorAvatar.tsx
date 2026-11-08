"use client";

import ReviewMediaImage from "./ReviewMediaImage";
import type { ReviewImageVariant } from "@/lib/reviewsApi";
import { useState } from "react";

type ReviewAuthorAvatarProps = {
  readonly src: string | null;
  readonly sizes?: string;
  readonly variants?: ReadonlyArray<ReviewImageVariant>;
};

export default function ReviewAuthorAvatar({ src, variants, sizes = "(min-width: 922px) 52px, 44px" }: ReviewAuthorAvatarProps) {
  const [failedSrc, setFailedSrc] = useState<string | null>(null);

  return (
    <span className="review-author-avatar" aria-hidden="true">
      {src && src !== failedSrc ? (
        <ReviewMediaImage src={src} variants={variants} role="avatar" alt="" width={64} height={64} sizes={sizes} onError={() => setFailedSrc(src)} />
      ) : (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" focusable="false">
          <circle cx="12" cy="8" r="3.25" />
          <path d="M5 20v-1.5a7 7 0 0 1 14 0V20" />
        </svg>
      )}
    </span>
  );
}
