"use client";

import { useLayoutEffect } from "react";
import { animateHomeHero } from "./heroSceneMotion";

export default function HomeHeroMotion() {
  useLayoutEffect(() => {
    const hero = document.querySelector<HTMLElement>(".home-hero");
    if (hero) return animateHomeHero(hero);
  }, []);
  return null;
}
