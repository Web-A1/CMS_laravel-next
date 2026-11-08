import type { ReviewImageVariant } from "../../lib/reviewsApi.ts";

export type ReviewImageRole = "avatar" | "preview" | "display" | "viewer";

export function reviewImageCandidates(variants: ReadonlyArray<ReviewImageVariant>, role: ReviewImageRole): ReadonlyArray<ReviewImageVariant> {
  const limit = role === "preview" ? 320 : role === "display" ? 1600 : Infinity;
  const selected = variants.filter((variant) => variant.width <= limit);
  if (selected.length > 0) return selected;
  const minimum = Math.min(...variants.map((variant) => variant.width));
  return variants.filter((variant) => variant.width === minimum);
}

export function reviewImageSrcSet(variants: ReadonlyArray<ReviewImageVariant>, format: ReviewImageVariant["format"]): string {
  return [...variants].filter((variant) => variant.format === format).sort((a, b) => a.width - b.width)
    .map((variant) => `${variant.url} ${variant.width}w`).join(", ");
}

export function reviewContainedImageSizes(width: number | null, height: number | null, role: "display" | "viewer", zoom = 1): string {
  if (!width || !height) return role === "viewer" ? "100vw" : "(min-width: 900px) min(50vw, 700px), calc(100vw - 32px)";
  const ratio = width / height;
  if (role === "viewer") {
    // The CSS viewer fits within both viewport dimensions; transforms do not update srcset selection.
    return zoom > 1 ? `${width}px` : `min(calc(100vw - 24px), calc((100dvh - 24px) * ${ratio}))`;
  }
  // A conservative bound for the different existing modal modes, including mobile screenshot-source.
  return `(min-width: 900px) min(50vw, 700px, ${Math.ceil(470 * ratio)}px), min(calc(100vw - 32px), calc(80dvh * ${ratio}))`;
}
