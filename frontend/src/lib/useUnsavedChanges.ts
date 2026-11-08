"use client";

import { useEffect } from "react";
import { BEFORE_ACCOUNT_LOGOUT_EVENT, CLIENT_ACCOUNT_CLEARED_EVENT } from "./clientAccount";

export const BEFORE_INTERNAL_NAVIGATION_EVENT = "cms:before-internal-navigation";

export function requestInternalNavigation(): boolean {
  return window.dispatchEvent(new Event(BEFORE_INTERNAL_NAVIGATION_EVENT, { cancelable: true }));
}

export function useUnsavedChanges(dirty: boolean): void {
  useEffect(() => {
    if (!dirty) return;
    let leaving = false;
    let logoutApproved = false;
    const confirmLeave = () => window.confirm("Есть несохранённые изменения. Покинуть страницу без сохранения?");
    const beforeUnload = (event: BeforeUnloadEvent) => { if (!leaving) event.preventDefault(); };
    const beforeLogout = (event: Event) => { logoutApproved = confirmLeave(); if (!logoutApproved) event.preventDefault(); };
    const beforeInternalNavigation = (event: Event) => { if (!confirmLeave()) event.preventDefault(); };
    const accountCleared = (event: Event) => {
      if (logoutApproved && event instanceof CustomEvent && event.detail?.reason === "logout") leaving = true;
    };
    const followLink = (event: MouseEvent) => {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      const link = event.target instanceof Element ? event.target.closest<HTMLAnchorElement>("a[href]") : null;
      if (!link || link.target === "_blank" || link.hasAttribute("download")) return;
      const destination = new URL(link.href, window.location.href);
      if (destination.origin === window.location.origin && destination.pathname === window.location.pathname && destination.search === window.location.search) return;
      if (!confirmLeave()) { event.preventDefault(); event.stopPropagation(); }
    };
    window.addEventListener("beforeunload", beforeUnload);
    window.addEventListener(BEFORE_ACCOUNT_LOGOUT_EVENT, beforeLogout);
    window.addEventListener(BEFORE_INTERNAL_NAVIGATION_EVENT, beforeInternalNavigation);
    window.addEventListener(CLIENT_ACCOUNT_CLEARED_EVENT, accountCleared);
    document.addEventListener("click", followLink, true);
    return () => {
      window.removeEventListener("beforeunload", beforeUnload);
      window.removeEventListener(BEFORE_ACCOUNT_LOGOUT_EVENT, beforeLogout);
      window.removeEventListener(BEFORE_INTERNAL_NAVIGATION_EVENT, beforeInternalNavigation);
      window.removeEventListener(CLIENT_ACCOUNT_CLEARED_EVENT, accountCleared);
      document.removeEventListener("click", followLink, true);
    };
  }, [dirty]);
}
