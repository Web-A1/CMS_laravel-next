"use client";

import { useLayoutEffect, useRef } from "react";
import type { ReactNode } from "react";

type LearningSidebarFrameProps = {
  readonly children: ReactNode;
};

type SidebarScrollPosition = {
  readonly left: number;
  readonly top: number;
};

const sidebarScrollPositions = new Map<string, SidebarScrollPosition>();

function revealActiveItem(strip: HTMLElement, navigation: HTMLElement): void {
  if (strip.scrollWidth <= strip.clientWidth) {
    return;
  }

  const activeItem = navigation.querySelector<HTMLElement>('[aria-current="page"]');

  if (!activeItem) {
    return;
  }

  const visibleStart = strip.scrollLeft;
  const visibleEnd = visibleStart + strip.clientWidth;
  const itemStart = activeItem.offsetLeft;
  const itemEnd = itemStart + activeItem.offsetWidth;

  if (itemStart < visibleStart) {
    strip.scrollLeft = itemStart;
  } else if (itemEnd > visibleEnd) {
    strip.scrollLeft = itemEnd - strip.clientWidth;
  }
}

export function LearningSidebarFrame({ children }: LearningSidebarFrameProps) {
  const asideRef = useRef<HTMLElement>(null);
  const scrollFrameRef = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    const aside = asideRef.current;
    const scrollFrame = scrollFrameRef.current;

    if (!aside || !scrollFrame) {
      return;
    }

    let activeNavigation: HTMLElement | null = null;
    let activeNavigationScope: string | null = null;

    const rememberPosition = (): void => {
      if (!activeNavigationScope) {
        return;
      }

      sidebarScrollPositions.set(activeNavigationScope, {
        left: scrollFrame.scrollLeft,
        top: scrollFrame.scrollTop,
      });
    };

    const bindCurrentNavigation = (): void => {
      const navigation = aside.querySelector<HTMLElement>("[data-learning-navigation-scope]");
      const navigationScope = navigation?.dataset.learningNavigationScope;

      if (!navigation || !navigationScope) {
        activeNavigation = null;
        activeNavigationScope = null;
        return;
      }

      if (activeNavigation === navigation && activeNavigationScope === navigationScope) {
        return;
      }

      const savedPosition = sidebarScrollPositions.get(navigationScope);

      if (savedPosition) {
        scrollFrame.scrollLeft = savedPosition.left;
        scrollFrame.scrollTop = savedPosition.top;
      } else {
        scrollFrame.scrollLeft = 0;
        scrollFrame.scrollTop = 0;
        revealActiveItem(scrollFrame, navigation);
      }

      activeNavigation = navigation;
      activeNavigationScope = navigationScope;
      rememberPosition();
    };

    const observer = new MutationObserver(bindCurrentNavigation);
    observer.observe(aside, { childList: true, subtree: true });
    scrollFrame.addEventListener("scroll", rememberPosition, { passive: true });
    bindCurrentNavigation();

    return () => {
      rememberPosition();
      observer.disconnect();
      scrollFrame.removeEventListener("scroll", rememberPosition);
    };
  }, []);

  return (
    <aside ref={asideRef} className="lk-learning-sidebar-frame min-w-0" data-ui="lk.learning.sidebarFrame">
      <div ref={scrollFrameRef} className="lk-learning-sidebar-scroll-frame min-w-0">
        {children}
      </div>
    </aside>
  );
}
