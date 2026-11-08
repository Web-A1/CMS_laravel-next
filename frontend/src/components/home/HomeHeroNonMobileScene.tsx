import type { CSSProperties } from "react";
import GeneratedImage from "@/components/content/GeneratedImage";
import media from "./heroNonMobileMedia.json";
import shadows from "./heroNonMobileShadows.json";
import { HOME_HERO_MOTION } from "./homeMotion";

// Owner-approved Safari image boxes on the 1701px scene, including transparent margins.
// The development editor can still adjust them without changing the assets.
export const HERO_NON_MOBILE_CARDS = [
  { id: "1", left: 150, top: 576, size: 321.578125, shadowX: -26, shadowY: 33 },
  { id: "2", left: -31, top: 208, size: 467.234375, shadowX: -29, shadowY: 48 },
  { id: "3", left: 323.1875, top: 28, size: 294.53125, shadowX: -32, shadowY: 48 },
  { id: "4", left: 439.671875, top: 271, size: 234.28125, shadowX: -43, shadowY: 60 },
  { id: "5", left: 767.515625, top: 56.984375, size: 167.296875, shadowX: 20, shadowY: 22 },
  { id: "6", left: 1106.859375, top: 285, size: 199.53125, shadowX: 36, shadowY: 48 },
  { id: "7", left: 1201.28125, top: 21.703125, size: 292.09375, shadowX: 12, shadowY: 45 },
  { id: "8", left: 1185.34375, top: 159.921875, size: 624.484375, shadowX: 45, shadowY: 55 },
  { id: "9", left: 1323.65625, top: 682.578125, size: 206.25, shadowX: 35, shadowY: 57 },
] as const;

const referenceWidth = 1701;
// Owner-approved IAB composition: 1023px viewport, 1008px scene.
const tabletCards: Readonly<Record<string, { left: number; top: number; size: number }>> = {
  "2": { left: -377.5, top: 208, size: 467.234375 },
  "3": { left: -23.3125, top: 28, size: 294.53125 },
  "4": { left: 78.171875, top: 242, size: 256.28125 },
  "5": { left: 421.015625, top: 32.984375, size: 150.296875 },
  "6": { left: 760.359375, top: 285, size: 199.53125 },
  "7": { left: 854.78125, top: 21.703125, size: 292.09375 },
};
const tabletReferenceWidth = 1008;
// Owner-approved IAB composition: 770px viewport, 755px scene.
const narrowCards: Readonly<Record<string, { left: number; top: number; size: number; sceneWidth?: number }>> = {
  "2": { left: -504, top: 208, size: 467.234375 },
  "3": { left: -100.8125, top: 9, size: 294.53125 },
  "4": { left: -42.328125, top: 218, size: 262.28125 },
  "5": { left: 295.015625, top: 32.984375, size: 131.296875, sceneWidth: 756 }, // Updated at viewport 771px.
  "6": { left: 594.859375, top: 254, size: 199.53125 },
  "7": { left: 624.28125, top: 13.703125, size: 247.09375 },
};
const narrowReferenceWidth = 755;

function interpolate(low: number, middle: number, high: number, centered = false, lowReferenceWidth = narrowReferenceWidth) {
  return `calc(${centered ? "50% + " : ""}${low}px + var(--hero-tablet-travel) * ${(middle - low) / (tabletReferenceWidth - lowReferenceWidth)} + var(--hero-desktop-travel) * ${(high - middle) / (referenceWidth - tabletReferenceWidth)})`;
}

export default function HomeHeroNonMobileScene() {
  return <div className="home-hero__non-mobile-scene" aria-hidden="true" data-ui="home.hero.nonMobileScene" style={{ "--hero-shadow-opacity": HOME_HERO_MOTION.shadowOpacity } as CSSProperties}>
    {HERO_NON_MOBILE_CARDS.map(card => {
      // Hidden cards keep their desktop projection until their visibility breakpoint.
      const middle = tabletCards[card.id] ?? { ...card, left: card.left - (referenceWidth - tabletReferenceWidth) / 2 };
      const low = narrowCards[card.id] ?? { ...card, left: card.left - (referenceWidth - narrowReferenceWidth) / 2 };
      const lowReferenceWidth = narrowCards[card.id]?.sceneWidth ?? narrowReferenceWidth;
      const mediaQuery = card.id === "5" ? "(width > 770px)" : tabletCards[card.id] ? "(width >= 770px)" : "(min-width: 64rem)";
      return <div
        key={card.id}
        className={`home-hero__non-mobile-card home-hero__non-mobile-card--${card.id}`}
        data-card={card.id}
        data-tablet-visible={Boolean(tabletCards[card.id])}
        style={{
          "--hero-tablet-travel": `calc(clamp(${lowReferenceWidth}px, 100cqw, ${tabletReferenceWidth}px) - ${lowReferenceWidth}px)`,
          "--hero-card-left": interpolate(low.left - lowReferenceWidth / 2, middle.left - tabletReferenceWidth / 2, card.left - referenceWidth / 2, true, lowReferenceWidth),
          "--hero-card-top": interpolate(low.top, middle.top, card.top, false, lowReferenceWidth),
          "--hero-card-width": interpolate(low.size, middle.size, card.size, false, lowReferenceWidth),
          "--hero-card-shadow-x": `${card.shadowX}px`, "--hero-card-shadow-y": `${card.shadowY}px`,
          "--hero-shadow-pad-x": `${shadows[card.id].paddingX}%`,
          "--hero-shadow-pad-y": `${shadows[card.id].paddingY}%`,
          "--hero-card-aspect": `${media[card.id].width} / ${media[card.id].height}`,
        } as CSSProperties}
      >
        <span className="home-hero__float-shadow">
          <GeneratedImage image={shadows[card.id]} media={mediaQuery} alt="" loading="eager" fetchPriority="low" />
        </span>
        <GeneratedImage
          image={media[card.id]}
          media={mediaQuery}
          sizes={`${Math.max(low.size, middle.size, card.size)}px`}
          alt=""
          loading="eager"
        />
      </div>;
    })}
  </div>;
}
