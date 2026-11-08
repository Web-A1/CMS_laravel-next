"use client";

import type { ComponentPropsWithRef, KeyboardEvent } from "react";

import { keyboardHorizontalScrollTarget } from "./horizontalScrollPolicy";

type HorizontalScrollRegionProps = ComponentPropsWithRef<"div">;

export default function HorizontalScrollRegion({ onKeyDown, ...props }: HorizontalScrollRegionProps) {
  function handleKeyDown(event: KeyboardEvent<HTMLDivElement>): void {
    onKeyDown?.(event);

    if (event.defaultPrevented || event.target !== event.currentTarget) {
      return;
    }

    const target = keyboardHorizontalScrollTarget(event.key, event.currentTarget);
    if (target === null) {
      return;
    }

    event.preventDefault();
    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    event.currentTarget.scrollTo({ left: target, behavior: reduceMotion ? "instant" : "smooth" });
  }

  return <div {...props} onKeyDown={handleKeyDown} />;
}
