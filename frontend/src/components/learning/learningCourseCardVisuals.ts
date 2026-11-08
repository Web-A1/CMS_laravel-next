import type { CSSProperties } from "react";

import type { StaticGeneratedImageAsset } from "@/components/content/GeneratedImage";

type LearningCourseVisualAccess = "full" | "partial" | "none";

type LearningCourseImageDimensions = {
  readonly height: number;
  readonly width: number;
};

export type LearningCourseCardVisual = {
  readonly image: StaticGeneratedImageAsset;
  readonly mutedImage: StaticGeneratedImageAsset;
  readonly style: CSSProperties;
};

const courseImageRevision = "02";

const learningCourseCardVisualsByPath: Readonly<Record<string, LearningCourseCardVisual>> = {
  "anatomiya/artrologiya": courseVisual(
    "anatomiya",
    "artrologiya",
    { width: 200, height: 336 },
    { inset: "1% 9% 1% 11%", opacity: 0.94 },
  ),
  "anatomiya/ehndokrinnaya-sistema": courseVisual(
    "anatomiya",
    "ehndokrinnaya-sistema",
    { width: 432, height: 282 },
    { inset: "9% -4% 9% -2%", opacity: 0.9 },
  ),
  "anatomiya/immunnaya-i-limfaticheskaya-sistema": courseVisual(
    "anatomiya",
    "immunnaya-i-limfaticheskaya-sistema",
    { width: 330, height: 336 },
    { inset: "2% 3% 2% 5%", opacity: 0.9 },
  ),
  "anatomiya/intensiv-po-dyhatelnoj-sisteme": courseVisual(
    "anatomiya",
    "intensiv-po-dyhatelnoj-sisteme",
    { width: 334, height: 336 },
    { inset: "1% 3% 1% 5%", opacity: 0.9 },
  ),
  "anatomiya/intensiv-po-mochepolovoj-sisteme": courseVisual(
    "anatomiya",
    "intensiv-po-mochepolovoj-sisteme",
    { width: 274, height: 336 },
    { inset: "1% 9% 1% 11%", opacity: 0.9 },
  ),
  "anatomiya/intensiv-po-perifericheskoj-nervnoj-sisteme": courseVisual(
    "anatomiya",
    "intensiv-po-perifericheskoj-nervnoj-sisteme",
    { width: 336, height: 336 },
    { inset: "-5% -12% -5% -8%", opacity: 0.9 },
  ),
  "anatomiya/intensiv-po-serdechno-sosudistoj-sisteme": courseVisual(
    "anatomiya",
    "intensiv-po-serdechno-sosudistoj-sisteme",
    { width: 328, height: 336 },
    { inset: "1% 3% 1% 5%", opacity: 0.9 },
  ),
  "anatomiya/intensiv-po-tsentralnoj-nervnoj-sisteme": courseVisual(
    "anatomiya",
    "intensiv-po-tsentralnoj-nervnoj-sisteme",
    { width: 344, height: 336 },
    { inset: "6% -1% 6% 1%", opacity: 0.9 },
  ),
  "anatomiya/miologiya": courseVisual(
    "anatomiya",
    "miologiya",
    { width: 326, height: 336 },
    { inset: "2% 4% 2% 6%", opacity: 0.9 },
  ),
  "anatomiya/osteologiya": courseVisual(
    "anatomiya",
    "osteologiya",
    { width: 310, height: 336 },
    { inset: "1% 5% 1% 7%", opacity: 0.9 },
  ),
  "anatomiya/pishchevaritelnaya-sistema": courseVisual(
    "anatomiya",
    "pishchevaritelnaya-sistema",
    { width: 268, height: 336 },
    { inset: "1% 7% 1% 9%", opacity: 0.9 },
  ),
  "biohimiya/ehnzimologiya": courseVisual(
    "biohimiya",
    "ehnzimologiya",
    { width: 304, height: 336 },
    { inset: "-8% -5% -8% -3%", opacity: 0.9 },
  ),
  "biohimiya/lipidnyj-obmen": courseVisual(
    "biohimiya",
    "lipidnyj-obmen",
    { width: 344, height: 336 },
    { inset: "1% 0% 1% 2%", opacity: 0.9 },
  ),
  "biohimiya/nutriomika": courseVisual(
    "biohimiya",
    "nutriomika",
    { width: 246, height: 336 },
    { inset: "-2% 3% -2% 7%", opacity: 0.9 },
  ),
  "bioorganicheskaya-himiya/bioorganicheskaya-himiya": courseVisual(
    "bioorganicheskaya-himiya",
    "bioorganicheskaya-himiya",
    { width: 432, height: 280 },
    { inset: "4% -22% 4% -18%", opacity: 0.92 },
  ),
  "gistologiya/obshchaya-gistologiya-tkani": courseVisual(
    "gistologiya",
    "obshchaya-gistologiya-tkani",
    { width: 388, height: 336 },
    { inset: "1% -4% 1% -2%", opacity: 0.9 },
  ),
  "gistologiya/tsitologiya": courseVisual(
    "gistologiya",
    "tsitologiya",
    { width: 338, height: 336 },
    { inset: "1% 2% 1% 4%", opacity: 0.9 },
  ),
  "hirurgiya/obshchaya-hirurgiya": courseVisual(
    "hirurgiya",
    "obshchaya-hirurgiya",
    { width: 316, height: 336 },
    { inset: "-4% -8% -4% -6%", opacity: 0.92 },
  ),
  "latinskij-yazyk/latinskij-yazyk": courseVisual(
    "latinskij-yazyk",
    "latinskij-yazyk",
    { width: 432, height: 182 },
    { inset: "-8% -22% -8% -18%", opacity: 0.92 },
  ),
  "luchevaya-diagnostika/luchevaya-diagnostika": courseVisual(
    "luchevaya-diagnostika",
    "luchevaya-diagnostika",
    { width: 258, height: 384 },
    { inset: "-2% 8% -2% 11%", opacity: 0.9 },
  ),
  "mikrobiologiya/immunologiya": courseVisual(
    "mikrobiologiya",
    "immunologiya",
    { width: 334, height: 336 },
    { inset: "0% 1% 0% 3%", opacity: 0.9 },
  ),
  "mikrobiologiya/obshchaya-mikrobiologiya": courseVisual(
    "mikrobiologiya",
    "obshchaya-mikrobiologiya",
    { width: 336, height: 336 },
    { inset: "-5% -4% -5% -2%", opacity: 0.9 },
  ),
  "nevrologiya/dvigatelnye-narusheniya": courseVisual(
    "nevrologiya",
    "dvigatelnye-narusheniya",
    { width: 380, height: 336 },
    { inset: "-5% -12% -5% -8%", opacity: 0.9 },
  ),
  "nevrologiya/onmk-i-pnmk": courseVisual(
    "nevrologiya",
    "onmk-i-pnmk",
    { width: 368, height: 336 },
    { inset: "1% -3% 1% -1%", opacity: 0.9 },
  ),
  "patofiziologiya/narusheniya-teplovogo-obmena": courseVisual(
    "patofiziologiya",
    "narusheniya-teplovogo-obmena",
    { width: 258, height: 336 },
    { inset: "1% 7% 1% 9%", opacity: 0.9 },
  ),
  "patofiziologiya/patologiya-i-povrezhdenie-kletki": courseVisual(
    "patofiziologiya",
    "patologiya-i-povrezhdenie-kletki",
    { width: 352, height: 336 },
    { inset: "1% 0% 1% 2%", opacity: 0.9 },
  ),
  "patofiziologiya/patologiya-vospaleniya": courseVisual(
    "patofiziologiya",
    "patologiya-vospaleniya",
    { width: 432, height: 300 },
    { inset: "8% -6% 8% -3%", opacity: 0.9 },
  ),
  "patologicheskaya-anatomiya/intensiv-vnutrikletochnye-nakopleniya": courseVisual(
    "patologicheskaya-anatomiya",
    "intensiv-vnutrikletochnye-nakopleniya",
    { width: 280, height: 336 },
    { inset: "1% 5% 1% 7%", opacity: 0.9 },
  ),
  "propedevtika-vnutrennih-boleznej/intensiv-ehkg": courseVisual(
    "propedevtika-vnutrennih-boleznej",
    "intensiv-ehkg",
    { width: 384, height: 336 },
    { inset: "-5% -12% -5% -8%", opacity: 0.9 },
  ),
  "propedevtika-vnutrennih-boleznej/propedevtika-dyhatelnoj-sistemy": courseVisual(
    "propedevtika-vnutrennih-boleznej",
    "propedevtika-dyhatelnoj-sistemy",
    { width: 336, height: 336 },
    { inset: "1% 2% 1% 4%", opacity: 0.9 },
  ),
  "propedevtika-vnutrennih-boleznej/propedevtika-mochepolovoj-sistemy": courseVisual(
    "propedevtika-vnutrennih-boleznej",
    "propedevtika-mochepolovoj-sistemy",
    { width: 354, height: 336 },
    { inset: "1% 0% 1% 2%", opacity: 0.9 },
  ),
  "propedevtika-vnutrennih-boleznej/propedevtika-pishchevaritelnoj-sistemy": courseVisual(
    "propedevtika-vnutrennih-boleznej",
    "propedevtika-pishchevaritelnoj-sistemy",
    { width: 352, height: 336 },
    { inset: "3% 2% 5% 4%", opacity: 0.9 },
  ),
};

function learningPathSlugsFromHref(href: string): { courseSlug: string | null; subjectSlug: string | null } {
  const normalizedHref = href.replace(/^https?:\/\/[^/]+/, "");
  const match = normalizedHref.match(/^\/(?:lk\/learning|subjects)\/([^/?#]+)\/([^/?#]+)/);

  if (!match) {
    return { courseSlug: null, subjectSlug: null };
  }

  return {
    subjectSlug: decodeURIComponent(match[1] ?? ""),
    courseSlug: decodeURIComponent(match[2] ?? ""),
  };
}

function learningCourseVisualKeyFromHref(href: string): string | null {
  const { courseSlug, subjectSlug } = learningPathSlugsFromHref(href);

  return subjectSlug && courseSlug ? `${subjectSlug}/${courseSlug}` : null;
}

function courseImage(
  subjectSlug: string,
  courseSlug: string,
  dimensions: LearningCourseImageDimensions,
  variant: "color" | "muted",
): StaticGeneratedImageAsset {
  const suffix = variant === "muted" ? "course-muted" : "course";
  const basename = `learning-${subjectSlug}-${courseSlug}-${suffix}-${courseImageRevision}-${dimensions.width}x${dimensions.height}`;

  return {
    avifSrc: `/images/v2/subjects/generated/${basename}.avif`,
    webpSrc: `/images/v2/subjects/generated/${basename}.webp`,
    width: dimensions.width,
    height: dimensions.height,
  };
}

function courseVisual(
  subjectSlug: string,
  courseSlug: string,
  dimensions: LearningCourseImageDimensions,
  style: CSSProperties,
): LearningCourseCardVisual {
  return {
    image: courseImage(subjectSlug, courseSlug, dimensions, "color"),
    mutedImage: courseImage(subjectSlug, courseSlug, dimensions, "muted"),
    style,
  };
}

export function courseCardVisualFromHref(href: string): LearningCourseCardVisual | null {
  const visualKey = learningCourseVisualKeyFromHref(href);

  return visualKey ? learningCourseCardVisualsByPath[visualKey] ?? null : null;
}

export function courseCardVisualRegistryKeys(): readonly string[] {
  return Object.keys(learningCourseCardVisualsByPath);
}

export function courseCardImageForAccess(
  visual: LearningCourseCardVisual,
  access: LearningCourseVisualAccess,
): StaticGeneratedImageAsset {
  return access === "none" ? visual.mutedImage : visual.image;
}

export function courseBackgroundImage(image: StaticGeneratedImageAsset): string {
  return `image-set(url("${image.avifSrc}") type("image/avif"), url("${image.webpSrc}") type("image/webp"))`;
}
