"use client";

import { useEffect, useState } from "react";
import Button from "@/components/button/Button";
import ButtonArrow from "@/components/button/ButtonArrow";
import { hiddenControlTabIndex } from "@/components/navigation/focusAvailability";

const VISIBLE_SCROLL_OFFSET = 720;

export default function ScrollTopButton() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    let frameId: number | undefined;

    function updateVisibility(): void {
      setVisible(window.scrollY > VISIBLE_SCROLL_OFFSET);
      frameId = undefined;
    }

    function onScroll(): void {
      if (frameId !== undefined) return;
      frameId = window.requestAnimationFrame(updateVisibility);
    }

    frameId = window.requestAnimationFrame(updateVisibility);
    window.addEventListener("scroll", onScroll, { passive: true });

    return () => {
      if (frameId !== undefined) window.cancelAnimationFrame(frameId);
      window.removeEventListener("scroll", onScroll);
    };
  }, []);

  function handleClick(): void {
    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    window.scrollTo({ top: 0, behavior: reduceMotion ? "auto" : "smooth" });
  }

  return (
    <Button
      variant="utility"
      iconOnly
      fullWidth={false}
      type="button"
      className={`scroll-top ${visible ? "is-visible" : ""}`}
      aria-label="Вернуться наверх страницы"
      aria-hidden={!visible}
      tabIndex={hiddenControlTabIndex(visible)}
      data-ui="site.scrollTopButton"
      onClick={handleClick}
    >
      <ButtonArrow direction="up" chevron />
    </Button>
  );
}
