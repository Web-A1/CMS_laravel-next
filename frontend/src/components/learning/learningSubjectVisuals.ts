import type { CSSProperties } from "react";

import type { StaticGeneratedImageAsset } from "@/components/content/GeneratedImage";

export type LearningSubjectCardVisualState = "free" | "partial";

export type LearningSubjectImageByState = Partial<
  Record<LearningSubjectCardVisualState, StaticGeneratedImageAsset>
>;

export type LearningSubjectTypographyWord = {
  readonly className: string;
  readonly style: Pick<CSSProperties, "left" | "top">;
  readonly text: string;
};

export type LearningSubjectCardIllustration =
  | {
      readonly kind: "image";
      readonly image: StaticGeneratedImageAsset;
      readonly imageByState?: LearningSubjectImageByState;
      readonly style: CSSProperties;
    }
  | {
      readonly kind: "typography";
      readonly words: ReadonlyArray<LearningSubjectTypographyWord>;
    };

export type LearningSubjectCardVisual = {
  readonly illustration: LearningSubjectCardIllustration;
};

type LearningSubjectImageDimensions = {
  readonly height: number;
  readonly width: number;
};

function subjectImage(slug: string, dimensions: LearningSubjectImageDimensions): StaticGeneratedImageAsset {
  const basename = `learning-${slug}-v2-${dimensions.width}x${dimensions.height}`;

  return {
    avifSrc: `/images/v2/subjects/generated/${basename}.avif`,
    webpSrc: `/images/v2/subjects/generated/${basename}.webp`,
    width: dimensions.width,
    height: dimensions.height,
  };
}

function subjectVisual(
  slug: string,
  objectStyle: CSSProperties,
  dimensions: LearningSubjectImageDimensions,
  imageByState?: LearningSubjectImageByState,
): LearningSubjectCardVisual {
  return {
    illustration: {
      kind: "image",
      image: subjectImage(slug, dimensions),
      ...(imageByState ? { imageByState } : {}),
      style: objectStyle,
    },
  };
}

const latinSubjectTypographyWords = [
  {
    text: "VENA",
    className: "absolute whitespace-nowrap text-3xl italic leading-none text-slate-500/32",
    style: { left: "4%", top: "28%" },
  },
  {
    text: "COR",
    className: "absolute z-10 whitespace-nowrap text-5xl font-normal leading-none text-slate-500/45",
    style: { left: "34%", top: "36%" },
  },
  {
    text: "MUSCULUS",
    className: "absolute whitespace-nowrap text-xs font-normal leading-none text-slate-500/30",
    style: { left: "64%", top: "35%" },
  },
  {
    text: "NERVUS",
    className: "absolute whitespace-nowrap text-base font-normal leading-none text-slate-500/30",
    style: { left: "14%", top: "59%" },
  },
  {
    text: "ARTERIA",
    className: "absolute whitespace-nowrap text-xl font-normal leading-none text-slate-500/32",
    style: { left: "66%", top: "59%" },
  },
  {
    text: "C E R E B R U M",
    className: "absolute -translate-x-1/2 whitespace-nowrap text-xs font-normal leading-none text-slate-400/35",
    style: { left: "47%", top: "67%" },
  },
  {
    text: "CAVITAS",
    className:
      "home-subject-card__typography--mobile-only absolute whitespace-nowrap text-base font-normal leading-none text-slate-500/22",
    style: { left: "7%", top: "76%" },
  },
  {
    text: "MEDULLA",
    className:
      "home-subject-card__typography--mobile-only absolute whitespace-nowrap text-xs font-normal leading-none text-slate-500/24",
    style: { left: "57%", top: "82%" },
  },
  {
    text: "OS",
    className:
      "home-subject-card__typography--mobile-only absolute whitespace-nowrap text-xl italic leading-none text-slate-500/20",
    style: { left: "18%", top: "88%" },
  },
] as const satisfies ReadonlyArray<LearningSubjectTypographyWord>;

export const learningSubjectVisualsBySlug: Readonly<Record<string, LearningSubjectCardVisual>> = {
  anatomiya: subjectVisual(
    "anatomiya",
    { inset: "12% 2% -4% 52%", opacity: 0.98 },
    { width: 480, height: 480 },
    {
      free: {
        avifSrc: "/images/v2/subjects/generated/learning-anatomiya-muted-preview-01-480x480.avif",
        webpSrc: "/images/v2/subjects/generated/learning-anatomiya-muted-preview-01-480x480.webp",
        width: 480,
        height: 480,
      },
    },
  ),
  "bioorganicheskaya-himiya": subjectVisual(
    "bioorganicheskaya-himiya",
    { inset: "10% 0% 8% 60%", opacity: 0.96 },
    { width: 384, height: 384 },
    {
      free: {
        avifSrc: "/images/v2/subjects/generated/learning-bioorganicheskaya-himiya-muted-preview-03-384x384.avif",
        webpSrc: "/images/v2/subjects/generated/learning-bioorganicheskaya-himiya-muted-preview-03-384x384.webp",
        width: 384,
        height: 384,
      },
    },
  ),
  biohimiya: subjectVisual(
    "biohimiya",
    { inset: "4% -2% 10% 32%", opacity: 0.98 },
    { width: 576, height: 384 },
    {
      free: {
        avifSrc: "/images/v2/subjects/generated/learning-biohimiya-muted-preview-01-576x384.avif",
        webpSrc: "/images/v2/subjects/generated/learning-biohimiya-muted-preview-01-576x384.webp",
        width: 576,
        height: 384,
      },
    },
  ),
  farmakologiya: subjectVisual(
    "farmakologiya",
    { inset: "7% -8% 2% 32%", opacity: 0.98 },
    { width: 472, height: 472 },
    {
      free: {
        avifSrc: "/images/v2/subjects/generated/learning-farmakologiya-muted-preview-04-472x472.avif",
        webpSrc: "/images/v2/subjects/generated/learning-farmakologiya-muted-preview-04-472x472.webp",
        width: 472,
        height: 472,
      },
    },
  ),
  fiziologiya: subjectVisual(
    "fiziologiya",
    { inset: "7% 0% 13% 52%", opacity: 0.96 },
    { width: 416, height: 416 },
    {
      free: {
        avifSrc: "/images/v2/subjects/generated/learning-fiziologiya-muted-preview-01-416x416.avif",
        webpSrc: "/images/v2/subjects/generated/learning-fiziologiya-muted-preview-01-416x416.webp",
        width: 416,
        height: 416,
      },
    },
  ),
  gistologiya: subjectVisual(
    "gistologiya",
    { inset: "12% 6% 16% 52%", opacity: 0.98 },
    { width: 416, height: 416 },
    {
      free: {
        avifSrc: "/images/v2/subjects/generated/learning-gistologiya-muted-preview-01-416x416.avif",
        webpSrc: "/images/v2/subjects/generated/learning-gistologiya-muted-preview-01-416x416.webp",
        width: 416,
        height: 416,
      },
    },
  ),
  hirurgiya: subjectVisual(
    "hirurgiya",
    { inset: "8% 2% 16% 52%", opacity: 0.98 },
    { width: 424, height: 424 },
    {
      partial: {
        avifSrc: "/images/v2/subjects/generated/learning-hirurgiya-steel-preview-03-424x424.avif",
        webpSrc: "/images/v2/subjects/generated/learning-hirurgiya-steel-preview-03-424x424.webp",
        width: 424,
        height: 424,
      },
      free: {
        avifSrc: "/images/v2/subjects/generated/learning-hirurgiya-steel-muted-preview-03-424x424.avif",
        webpSrc: "/images/v2/subjects/generated/learning-hirurgiya-steel-muted-preview-03-424x424.webp",
        width: 424,
        height: 424,
      },
    },
  ),
  "latinskij-yazyk": {
    illustration: {
      kind: "typography",
      words: latinSubjectTypographyWords,
    },
  },
  "luchevaya-diagnostika": subjectVisual(
    "luchevaya-diagnostika",
    { inset: "10% 0% 14% 36%", opacity: 0.98 },
    { width: 576, height: 384 },
    {
      free: {
        avifSrc: "/images/v2/subjects/generated/learning-luchevaya-diagnostika-muted-preview-01-576x384.avif",
        webpSrc: "/images/v2/subjects/generated/learning-luchevaya-diagnostika-muted-preview-01-576x384.webp",
        width: 576,
        height: 384,
      },
    },
  ),
  mikrobiologiya: subjectVisual(
    "mikrobiologiya",
    { inset: "8% 0% 8% 48%", opacity: 0.98 },
    { width: 432, height: 432 },
    {
      free: {
        avifSrc: "/images/v2/subjects/generated/learning-mikrobiologiya-muted-preview-03-432x432.avif",
        webpSrc: "/images/v2/subjects/generated/learning-mikrobiologiya-muted-preview-03-432x432.webp",
        width: 432,
        height: 432,
      },
    },
  ),
  nevrologiya: subjectVisual(
    "nevrologiya",
    { inset: "14% 6% 18% 30%", opacity: 0.96 },
    { width: 528, height: 352 },
    {
      free: {
        avifSrc: "/images/v2/subjects/generated/learning-nevrologiya-muted-preview-01-528x352.avif",
        webpSrc: "/images/v2/subjects/generated/learning-nevrologiya-muted-preview-01-528x352.webp",
        width: 528,
        height: 352,
      },
    },
  ),
  patofiziologiya: subjectVisual(
    "patofiziologiya",
    { inset: "12% 2% 12% 56%", opacity: 0.94 },
    { width: 416, height: 416 },
    {
      free: {
        avifSrc: "/images/v2/subjects/generated/learning-patofiziologiya-muted-preview-01-416x416.avif",
        webpSrc: "/images/v2/subjects/generated/learning-patofiziologiya-muted-preview-01-416x416.webp",
        width: 416,
        height: 416,
      },
    },
  ),
  "patologicheskaya-anatomiya": subjectVisual(
    "patologicheskaya-anatomiya",
    {
      inset: "8% 2% 8% 54%",
      opacity: 0.97,
      transform: "rotate(30deg)",
    },
    { width: 608, height: 608 },
    {
      free: {
        avifSrc: "/images/v2/subjects/generated/learning-patologicheskaya-anatomiya-muted-preview-03-608x608.avif",
        webpSrc: "/images/v2/subjects/generated/learning-patologicheskaya-anatomiya-muted-preview-03-608x608.webp",
        width: 608,
        height: 608,
      },
    },
  ),
  "propedevtika-vnutrennih-boleznej": subjectVisual(
    "propedevtika-vnutrennih-boleznej",
    { inset: "10% 0% 14% 56%", opacity: 0.98 },
    { width: 392, height: 392 },
    {
      free: {
        avifSrc: "/images/v2/subjects/generated/learning-propedevtika-vnutrennih-boleznej-muted-preview-02-392x392.avif",
        webpSrc: "/images/v2/subjects/generated/learning-propedevtika-vnutrennih-boleznej-muted-preview-02-392x392.webp",
        width: 392,
        height: 392,
      },
    },
  ),
};
