import Image from "next/image";
import type { CSSProperties, ReactEventHandler } from "react";
import type { ReviewImageVariant } from "@/lib/reviewsApi";
import { reviewImageCandidates, reviewImageSrcSet, type ReviewImageRole } from "./reviewMediaVariants";

interface ReviewMediaImageProps {
  readonly src: string;
  readonly alt: string;
  readonly width: number;
  readonly height: number;
  readonly sizes: string;
  readonly role: ReviewImageRole;
  readonly variants?: ReadonlyArray<ReviewImageVariant>;
  readonly className?: string;
  readonly style?: CSSProperties;
  readonly onError?: ReactEventHandler<HTMLImageElement>;
}

export default function ReviewMediaImage({ variants = [], role, ...props }: ReviewMediaImageProps) {
  if (variants.length === 0) return <Image {...props} alt={props.alt} />;
  const candidates = reviewImageCandidates(variants, role);
  const webp = candidates.filter((variant) => variant.format === "webp").sort((a, b) => a.width - b.width);
  const fallback = webp.at(-1);
  if (!fallback) return <Image {...props} alt={props.alt} />;
  const avifSrcSet = reviewImageSrcSet(candidates, "avif");

  return (
    <picture className="review-media-picture">
      {avifSrcSet ? <source type="image/avif" srcSet={avifSrcSet} sizes={props.sizes} /> : null}
      {/* Files are pre-encoded; using Next Image here would create a second set of lossy derivatives. */}
      <img {...props} alt={props.alt} src={fallback.url} srcSet={reviewImageSrcSet(candidates, "webp")} loading="lazy" decoding="async" />
    </picture>
  );
}
