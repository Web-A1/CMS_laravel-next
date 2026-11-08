"use client";

import Link from "next/link";
import Image from "next/image";
import Button, { ButtonLink, ButtonRouteLink } from "@/components/button/Button";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import type { MouseEvent } from "react";
import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from "react";
import { CART_HIGHLIGHT_EVENT, CART_UPDATED_EVENT, readCartCount } from "../cart/cartState";
import { headerIsInert, resolveHeaderVisible } from "@/components/navigation/focusAvailability";
import {
  clientAccountApiBaseUrl,
  clearClientAccountAuthenticated,
  canOpenAdminWorkspace,
  CLIENT_ACCOUNT_PROFILE_UPDATED_EVENT,
  CLIENT_ACCOUNT_CLEARED_EVENT,
  hasClientAccountAuthenticatedMarker,
  loadClientAccountProfileResult,
  logoutClientAccount,
  resolveClientAccountAvatarUrl,
  resolveClientAccountHref,
  resolveClientAccountName,
  requestClientAccountLogout,
  isClientAccountProfile,
  type ClientAccountProfile,
  type ClientAccountResult,
} from "@/lib/clientAccount";
import { replaceWithFreshDocument } from "@/lib/authNavigation";
import { loadImpersonationContext, stopImpersonation } from "@/lib/impersonationApi";
import type { ImpersonationContext } from "@/lib/impersonationContract";

const menuItems = [
  { href: "/", label: "Главная" },
  { href: "/subjects", label: "Предметы" },
  { href: "/boxes", label: "Боксы" },
  { href: "/about", label: "О школе" },
  { href: "/articles", label: "Статьи" },
  { href: "/reviews", label: "Отзывы" },
  { href: "/faq", label: "Вопросы" },
];

const subjectMenuItems = [
  { href: "/subjects/anatomiya", label: "Анатомия" },
  { href: "/subjects/gistologiya", label: "Гистология" },
  { href: "/subjects/latinskij-yazyk", label: "Латинский язык" },
  { href: "/subjects/bioorganicheskaya-himiya", label: "Биоорганическая химия" },
  { href: "/subjects/mikrobiologiya", label: "Микробиология" },
  { href: "/subjects/fiziologiya", label: "Нормальная физиология" },
  { href: "/subjects/biohimiya", label: "Биохимия" },
  { href: "/subjects/patologicheskaya-anatomiya", label: "Патологическая анатомия" },
  { href: "/subjects/patofiziologiya", label: "Патологическая физиология" },
  { href: "/subjects/farmakologiya", label: "Фармакология" },
  { href: "/subjects/propedevtika-vnutrennih-boleznej", label: "Пропедевтика внутренних болезней" },
  { href: "/subjects/hirurgiya", label: "Хирургия" },
  { href: "/subjects/nevrologiya", label: "Неврология" },
  { href: "/subjects/luchevaya-diagnostika", label: "Лучевая диагностика" },
] as const;

const accountItems = [
  { href: "/account", label: "Личный кабинет", variant: "primary" },
] as const;

const HEADER_HIDE_OFFSET = 64;
const SCROLL_DIRECTION_THRESHOLD = 4;
const MENU_FOCUSABLE_SELECTOR =
  'a[href]:not([tabindex="-1"]), button:not([disabled]):not([tabindex="-1"]), [tabindex]:not([tabindex="-1"])';
type MenuFocusOrigin = "keyboard" | "pointer";

function subscribeCartCount(onStoreChange: () => void): () => void {
  window.addEventListener(CART_UPDATED_EVENT, onStoreChange);
  return () => window.removeEventListener(CART_UPDATED_EVENT, onStoreChange);
}

function getServerCartCountSnapshot(): number {
  return 0;
}

function getMenuFocusableElements(dialog: HTMLDialogElement): ReadonlyArray<HTMLElement> {
  return Array.from(dialog.querySelectorAll<HTMLElement>(MENU_FOCUSABLE_SELECTOR));
}

function UserAvatarFallbackIcon() {
  return (
    <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false" fill="currentColor">
      <path d="M8 2a2.84 2.84 0 0 0-1.12.221c-.345.141-.651.348-.906.615v.003l-.001.002c-.248.269-.44.592-.574.96-.137.367-.203.769-.203 1.2 0 .435.065.841.203 1.209.135.361.327.68.574.95l.001.002c.254.267.558.477.901.624v.003c.346.141.723.21 1.12.21.395 0 .77-.069 1.117-.21v-.002c.343-.147.644-.357.892-.625.255-.268.45-.59.586-.952.138-.368.204-.774.204-1.21h.01c0-.43-.065-.831-.203-1.198a2.771 2.771 0 0 0-.585-.963 2.5 2.5 0 0 0-.897-.618A2.835 2.835 0 0 0 7.999 2zM8.024 10.002c-2.317 0-3.561.213-4.486.91-.462.35-.767.825-.939 1.378-.172.553-.226.975-.228 1.71L8 14.002h5.629c-.001-.736-.052-1.159-.225-1.712-.172-.553-.477-1.027-.94-1.376-.923-.697-2.124-.912-4.44-.912z" />
    </svg>
  );
}

function LogoutIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" strokeWidth="1.8">
      <path d="M15 17l5-5-5-5" strokeLinecap="round" strokeLinejoin="round" />
      <path d="M20 12H9" strokeLinecap="round" />
      <path d="M12 4H5a2 2 0 00-2 2v12a2 2 0 002 2h7" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function ReturnToAdminIcon() {
  return (
    <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false" fill="currentColor">
      <path d="M8 4.809V2.25c0-.256-.098-.512-.293-.708C7.512 1.347 7.256 1.25 7 1.25s-.512.097-.707.292L0 7.75l6.293 6.207c.195.195.451.293.707.293s.512-.098.707-.293S8 13.505 8 13.25v-2.489c2.75.068 5.755.566 8 3.989v-1C16 9.117 12.5 5.307 8 4.809Z" />
    </svg>
  );
}

function AccountAvatar({ avatarUrl }: { readonly avatarUrl: string }) {
  return (
    <span className="header__avatar" aria-hidden="true">
      {avatarUrl === "" ? (
        <UserAvatarFallbackIcon />
      ) : (
        <Image className="header__avatar-image" src={avatarUrl} alt="" width={32} height={32} loading="eager" fetchPriority="high" unoptimized />
      )}
    </span>
  );
}

function SubjectsMenu({ onNavigate }: { readonly onNavigate: (href: string, event: MouseEvent<HTMLAnchorElement>) => void }) {
  const [open, setOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement | null>(null);
  const triggerRef = useRef<HTMLAnchorElement | null>(null);

  useEffect(() => {
    if (!open) return;
    const dismiss = (event: KeyboardEvent): void => {
      if (event.key !== "Escape") return;
      setOpen(false);
      if (menuRef.current?.contains(document.activeElement)) triggerRef.current?.focus();
    };
    document.addEventListener("keydown", dismiss);
    return () => document.removeEventListener("keydown", dismiss);
  }, [open]);

  return (
    <div
      className="header__nav-item header__nav-item--subjects"
      data-ui="header.subjectsMenu"
      data-open={open}
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={(event) => {
        if (!event.currentTarget.contains(document.activeElement)) setOpen(false);
      }}
      onFocus={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget)) setOpen(true);
      }}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget) && !event.currentTarget.matches(":hover")) setOpen(false);
      }}
    >
      <Link ref={triggerRef} href="/subjects" onClick={(event) => onNavigate("/subjects", event)} className="header__nav-link" aria-haspopup="true" aria-expanded={open} aria-controls="header-subjects-panel">
        Предметы
      </Link>
      <div ref={menuRef} id="header-subjects-panel" className="header__subjects-menu" data-ui="header.subjectsMenu.panel" inert={!open}>
        <div className="header__subjects-menu-inner" role="group" aria-label="Предметы">
          {subjectMenuItems.map((subject) => (
            <Link key={subject.href} href={subject.href} onClick={(event) => onNavigate(subject.href, event)} className="header__subjects-link" data-ui="header.subjectsMenu.link">
              {subject.label}
            </Link>
          ))}
        </div>
      </div>
    </div>
  );
}

function SiteHeaderContent({ initialAccount }: { readonly initialAccount: ClientAccountResult }) {
  const router = useRouter();
  const pathname = usePathname();
  const isAdminRoute = pathname === "/admin" || pathname.startsWith("/admin/");
  const isAuthRoute = pathname === "/auth";
  const searchParams = useSearchParams();
  const apiBaseUrl = clientAccountApiBaseUrl();
  const [menuOpen, setMenuOpen] = useState(false);
  const [menuChromeOpen, setMenuChromeOpen] = useState(false);
  const [menuFocusOrigin, setMenuFocusOrigin] = useState<MenuFocusOrigin>("pointer");
  const [cartBump, setCartBump] = useState(false);
  const [accountProfile, setAccountProfile] = useState<ClientAccountProfile | null>(
    initialAccount.status === "authenticated" ? initialAccount.profile : null,
  );
  const [isAccountProfileResolved, setIsAccountProfileResolved] = useState(initialAccount.status !== "unavailable");
  const [impersonation, setImpersonation] = useState<ImpersonationContext | null>(
    initialAccount.status === "authenticated" ? initialAccount.profile.impersonation ?? null : null,
  );
  const accountRevisionRef = useRef(0);
  const [logoutError, setLogoutError] = useState("");
  const [impersonationError, setImpersonationError] = useState("");
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const [isReturningToAdmin, setIsReturningToAdmin] = useState(false);
  const logoutInFlightRef = useRef(false);
  const [headerCanHideInViewport, setHeaderCanHideInViewport] = useState(true);
  const [headerVisible, setHeaderVisible] = useState(true);
  const cartCount = useSyncExternalStore(subscribeCartCount, readCartCount, getServerCartCountSnapshot);
  const burgerButtonRef = useRef<HTMLButtonElement | null>(null);
  const headerRef = useRef<HTMLElement | null>(null);
  const menuDialogRef = useRef<HTMLDialogElement | null>(null);
  const menuCloseButtonRef = useRef<HTMLButtonElement | null>(null);
  const menuChromeFrameRef = useRef<number | undefined>(undefined);
  const lastScrollYRef = useRef(0);
  const restoreFocusOnMenuCloseRef = useRef(false);
  const checkedPublicAccountProfileRef = useRef(false);
  const routeSignature = `${pathname}?${searchParams.toString()}`;
  const routeSignatureRef = useRef(routeSignature);
  const isProtectedAccountRoute = isAdminRoute || pathname === "/account" || pathname === "/lk" || pathname.startsWith("/lk/");
  const headerSurface = pathname === "/" ? "glass" : "brand";
  const visibleImpersonation = impersonation;
  const isImpersonating = visibleImpersonation?.status === "active";
  const showReturnToAdmin = visibleImpersonation?.status === "active" || visibleImpersonation?.status === "expired";
  const showFloatingCart = !isAdminRoute && !isImpersonating && cartCount > 0 && pathname !== "/" && pathname !== "/auth" && pathname !== "/account" && pathname !== "/lk" && pathname !== "/lk/cart";

  const cancelMenuChromeFrame = useCallback(function cancelMenuChromeFrame(): void {
    if (menuChromeFrameRef.current === undefined) return;
    window.cancelAnimationFrame(menuChromeFrameRef.current);
    menuChromeFrameRef.current = undefined;
  }, []);

  const animateMenuChromeOpen = useCallback(function animateMenuChromeOpen(): void {
    cancelMenuChromeFrame();
    setMenuChromeOpen(false);
    menuChromeFrameRef.current = window.requestAnimationFrame(() => {
      menuChromeFrameRef.current = undefined;
      setMenuChromeOpen(true);
    });
  }, [cancelMenuChromeFrame]);

  const closeMenu = useCallback(function closeMenu({ restoreFocus = false }: { readonly restoreFocus?: boolean } = {}): void {
    cancelMenuChromeFrame();
    setMenuChromeOpen(false);
    restoreFocusOnMenuCloseRef.current = restoreFocus;
    setMenuOpen(false);
  }, [cancelMenuChromeFrame]);

  function toggleMenu(origin: MenuFocusOrigin): void {
    if (menuOpen) {
      closeMenu({ restoreFocus: origin === "keyboard" });
      return;
    }

    setMenuFocusOrigin(origin);
    setHeaderVisible(true);
    setMenuOpen(true);
    animateMenuChromeOpen();
  }

  function handleMenuCloseClick(event: MouseEvent<HTMLButtonElement>): void {
    closeMenu({ restoreFocus: event.detail === 0 });
  }

  function handleMenuPanelClick(event: MouseEvent<HTMLElement>): void {
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (target.closest(".menu__list, .menu__actions")) return;

    closeMenu();
  }

  useEffect(() => {
    document.body.classList.toggle("menu-scroll-lock", menuOpen);
    return () => document.body.classList.remove("menu-scroll-lock");
  }, [menuOpen]);

  useEffect(() => cancelMenuChromeFrame, [cancelMenuChromeFrame]);

  useEffect(() => {
    const mediaQuery = window.matchMedia("(max-width: 1023.98px)");

    function updateHeaderViewportState(): void {
      const canHideInViewport = mediaQuery.matches && !isAuthRoute;
      setHeaderCanHideInViewport(canHideInViewport);

      if (!canHideInViewport) {
        setHeaderVisible(true);
        closeMenu();
      }
    }

    updateHeaderViewportState();
    mediaQuery.addEventListener("change", updateHeaderViewportState);
    return () => mediaQuery.removeEventListener("change", updateHeaderViewportState);
  }, [closeMenu, isAuthRoute]);


  useEffect(() => {
    const dialog = menuDialogRef.current;
    if (!dialog) return;

    if (menuOpen) {
      if (!dialog.open && dialog.isConnected) {
        dialog.showModal();
      }
      return;
    }

    if (dialog.open) {
      dialog.close();
    }
  }, [menuOpen]);

  useEffect(() => {
    function onHashChange() {
      closeMenu();
    }

    window.addEventListener("hashchange", onHashChange);
    return () => window.removeEventListener("hashchange", onHashChange);
  }, [closeMenu]);

  useEffect(() => {
    if (routeSignatureRef.current === routeSignature) {
      return;
    }

    routeSignatureRef.current = routeSignature;
    closeMenu();
  }, [closeMenu, routeSignature]);

  useEffect(() => {
    router.prefetch("/account");
    router.prefetch("/lk");
    router.prefetch("/lk/cart");
  }, [router]);

  useEffect(() => {
    let isCancelled = false;
    const revision = accountRevisionRef.current;
    const isProtectedAccountRoute = isAdminRoute || pathname === "/account" || pathname === "/lk" || pathname.startsWith("/lk/");
    const hasAuthenticatedMarker = hasClientAccountAuthenticatedMarker();
    if (!isProtectedAccountRoute && !hasAuthenticatedMarker && checkedPublicAccountProfileRef.current) {
      return () => {
        isCancelled = true;
      };
    }
    (async () => {
      const result = await loadClientAccountProfileResult(apiBaseUrl);
      if (!isCancelled && revision === accountRevisionRef.current && result.status !== "unavailable") {
        if (result.status === "unauthenticated") clearClientAccountAuthenticated("profile-401");
        setAccountProfile(result.status === "authenticated" ? result.profile : null);
        setIsAccountProfileResolved(true);
        if (!isProtectedAccountRoute && !hasAuthenticatedMarker) {
          checkedPublicAccountProfileRef.current = true;
        }
      }
    })();

    return () => {
      isCancelled = true;
    };
  }, [apiBaseUrl, isAdminRoute, pathname]);

  useEffect(() => {
    let isCancelled = false;
    const revision = accountRevisionRef.current;
    void loadImpersonationContext(apiBaseUrl).then((context) => {
      if (!isCancelled && revision === accountRevisionRef.current && context !== null) {
        setImpersonation(context);
      }
    });

    return () => {
      isCancelled = true;
    };
  }, [apiBaseUrl, pathname]);

  useEffect(() => {
    function updateAccountProfile(event: Event): void {
      if (event instanceof CustomEvent && isClientAccountProfile(event.detail)) {
        accountRevisionRef.current += 1;
        setAccountProfile(event.detail);
        setIsAccountProfileResolved(true);
      }
    }

    function clearAccountProfile(): void {
      accountRevisionRef.current += 1;
      setAccountProfile(null);
      setImpersonation(null);
      setIsAccountProfileResolved(true);
    }

    window.addEventListener(CLIENT_ACCOUNT_PROFILE_UPDATED_EVENT, updateAccountProfile);
    window.addEventListener(CLIENT_ACCOUNT_CLEARED_EVENT, clearAccountProfile);
    return () => {
      window.removeEventListener(CLIENT_ACCOUNT_PROFILE_UPDATED_EVENT, updateAccountProfile);
      window.removeEventListener(CLIENT_ACCOUNT_CLEARED_EVENT, clearAccountProfile);
    };
  }, []);

  useEffect(() => {
    let frameId: number | undefined;
    let timeoutId: number | undefined;

    function onCartHighlight(): void {
      if (frameId !== undefined) window.cancelAnimationFrame(frameId);
      if (timeoutId !== undefined) window.clearTimeout(timeoutId);

      setCartBump(false);
      frameId = window.requestAnimationFrame(() => {
        setCartBump(true);
        timeoutId = window.setTimeout(() => setCartBump(false), 820);
      });
    }

    window.addEventListener(CART_HIGHLIGHT_EVENT, onCartHighlight);
    return () => {
      if (frameId !== undefined) window.cancelAnimationFrame(frameId);
      if (timeoutId !== undefined) window.clearTimeout(timeoutId);
      window.removeEventListener(CART_HIGHLIGHT_EVENT, onCartHighlight);
    };
  }, []);

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (!menuOpen || event.key !== "Tab") return;

      const dialog = menuDialogRef.current;
      if (!dialog?.open) return;

      const focusables = getMenuFocusableElements(dialog);
      if (!focusables.length) return;

      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      const active = document.activeElement;

      if (!dialog.contains(active)) {
        event.preventDefault();
        first.focus();
        return;
      }

      if (event.shiftKey && active === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    }

    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [menuOpen]);

  useEffect(() => {
    if (!menuOpen) return;
    menuCloseButtonRef.current?.focus({ preventScroll: true });
  }, [menuOpen]);

  useEffect(() => {
    let frameId: number | undefined;

    function updateHeaderVisibility(): void {
      const current = Math.max(window.scrollY, document.documentElement.scrollTop, document.body.scrollTop);

      if (isAuthRoute) {
        setHeaderVisible(true);
        lastScrollYRef.current = current;
        frameId = undefined;
        return;
      }

      const previous = lastScrollYRef.current;
      const delta = current - previous;

      const scrollDirection = delta > SCROLL_DIRECTION_THRESHOLD ? "down" : delta < -SCROLL_DIRECTION_THRESHOLD ? "up" : "none";
      const focusInsideHeader = headerRef.current?.contains(document.activeElement) ?? false;
      setHeaderVisible((currentVisible) => resolveHeaderVisible({
        atTop: current <= HEADER_HIDE_OFFSET,
        currentVisible,
        focusInsideHeader,
        menuOpen,
        scrollDirection,
      }));

      lastScrollYRef.current = current;
      frameId = undefined;
    }

    function onScroll(): void {
      if (frameId !== undefined) return;
      frameId = window.requestAnimationFrame(updateHeaderVisibility);
    }

    lastScrollYRef.current = window.scrollY;
    frameId = window.requestAnimationFrame(updateHeaderVisibility);
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => {
      if (frameId !== undefined) window.cancelAnimationFrame(frameId);
      window.removeEventListener("scroll", onScroll);
    };
  }, [isAuthRoute, menuOpen]);

  function isModifiedLinkClick(event: MouseEvent<HTMLAnchorElement>): boolean {
    return event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey;
  }

  function handleMenuLinkClick(href: string, event: MouseEvent<HTMLAnchorElement>): void {
    if (isModifiedLinkClick(event)) {
      return;
    }

    if (href === "/admin" || href.startsWith("/admin/")) {
      event.preventDefault();
      window.location.assign(href);
      return;
    }

    const isCurrentPath = href === pathname;
    if (isCurrentPath) {
      event.preventDefault();
      closeMenu();
      return;
    }

    const isInPageAnchor = href.startsWith("/#") && pathname === "/";
    if (isInPageAnchor) {
      closeMenu();
      return;
    }

    event.preventDefault();
    router.push(href);
  }

  async function handleLogout(): Promise<void> {
    if (logoutInFlightRef.current || !requestClientAccountLogout()) return;
    logoutInFlightRef.current = true;
    setLogoutError("");
    setIsLoggingOut(true);

    try {
      await logoutClientAccount(apiBaseUrl);
      setAccountProfile(null);
      closeMenu();
      replaceWithFreshDocument("/auth?mode=login");
    } catch {
      setLogoutError("Не удалось выйти. Повторите попытку.");
    } finally {
      logoutInFlightRef.current = false;
      setIsLoggingOut(false);
    }
  }

  async function handleReturnToAdmin(): Promise<void> {
    if (isReturningToAdmin || !showReturnToAdmin) return;

    setImpersonationError("");
    setIsReturningToAdmin(true);

    try {
      const result = await stopImpersonation(apiBaseUrl);
      replaceWithFreshDocument(
        result.return_path
          || visibleImpersonation?.return_path
          || "/admin/users",
      );
    } catch {
      setImpersonationError("Не удалось вернуться в админку. Повторите попытку.");
    } finally {
      setIsReturningToAdmin(false);
    }
  }

  const accountIdentity = accountProfile ? resolveClientAccountName(accountProfile) : "";
  const accountAvatarUrl = accountProfile ? resolveClientAccountAvatarUrl(accountProfile) : "";
  const accountHref = resolveClientAccountHref(accountProfile);
  const accountAriaLabel = accountHref === "/admin/articles"
    ? `Перейти в админку: ${accountIdentity}`
    : canOpenAdminWorkspace(accountProfile)
      ? `Перейти к настройкам профиля: ${accountIdentity}`
      : `Перейти в личный кабинет: ${accountIdentity}`;
  return (
    <>
      <header
        ref={headerRef}
        className={`header header--island header--${headerSurface} ${isAuthRoute ? "header--auth" : ""} is-pinned ${headerVisible ? "" : "is-hidden"}`}
        inert={headerIsInert(headerVisible, headerCanHideInViewport)}
        onFocusCapture={() => setHeaderVisible(true)}
        data-state={headerVisible ? "visible" : "hidden"}
        data-surface={headerSurface}
        data-ui="header.root"
      >
        <div className="header__row">
          <Link href="/" className="header__logo" data-ui="header.logo">
            <span className="header__mark" aria-hidden="true" />
            <span className="header__logo-text">christmedschool</span>
          </Link>
          <nav className="header__nav" aria-label="Основная навигация" data-ui="header.desktopNav">
            {menuItems.map((item) => (
              item.href === "/subjects" ? (
                <SubjectsMenu key={item.href} onNavigate={handleMenuLinkClick} />
              ) : (
                <Link
                  key={item.href}
                  href={item.href}
                  onClick={(event) => handleMenuLinkClick(item.href, event)}
                  className="header__nav-link"
                >
                  {item.label}
                </Link>
              )
            ))}
          </nav>
          <div className="header__actions" data-ui="header.actions">
            {showReturnToAdmin && !accountProfile ? (
              <Button fullWidth={false} variant="ghost" iconOnly size="sm" data-surface={headerSurface}
                type="button"
                onClick={() => void handleReturnToAdmin()}
                loading={isReturningToAdmin}
                aria-label="Вернуться в админку"
                title="Вернуться в админку"
                className="header__impersonation-button"
                data-ui="header.impersonationReturn"
                data-state={isReturningToAdmin ? "loading" : impersonation?.status ?? "idle"}
              >
                <ReturnToAdminIcon />
              </Button>
            ) : null}
            {accountProfile ? (
              <div className={`header__account ${isImpersonating ? "header__account--impersonating" : ""}`} data-ui="header.account" data-state={isImpersonating ? "impersonating" : "default"}>
                {showReturnToAdmin ? (
                  <Button fullWidth={false} variant="ghost" iconOnly size="sm" data-surface={headerSurface}
                    type="button"
                    onClick={() => void handleReturnToAdmin()}
                    loading={isReturningToAdmin}
                    aria-label="Вернуться в админку"
                    title="Вернуться в админку"
                    className="header__impersonation-button"
                    data-ui="header.impersonationReturn"
                    data-state={isReturningToAdmin ? "loading" : impersonation?.status ?? "idle"}
                  >
                    <ReturnToAdminIcon />
                  </Button>
                ) : null}
                <ButtonRouteLink variant="ghost" size="sm" fullWidth={false} data-surface={headerSurface}
                  href={accountHref}
                  prefetch={accountHref.startsWith("/admin/") ? false : null}
                  onClick={(event) => handleMenuLinkClick(accountHref, event)}
                  className="header__account-link"
                  data-ui="header.account.link"
                  aria-label={accountAriaLabel}
                >
                  <AccountAvatar avatarUrl={accountAvatarUrl} />
                  <span className="header__account-name">{accountIdentity}</span>
                </ButtonRouteLink>
                <Button fullWidth={false} variant="ghost" iconOnly size="sm" data-surface={headerSurface}
                  type="button"
                  onClick={() => void handleLogout()}
                  loading={isLoggingOut}
                  aria-label="Выйти из аккаунта"
                  title="Выйти"
                  className="header__logout-button"
                  data-ui="header.logoutButton"
                  data-state={isLoggingOut ? "loading" : "idle"}
                >
                  <LogoutIcon />
                </Button>
              </div>
            ) : !showReturnToAdmin && !isProtectedAccountRoute && isAccountProfileResolved ? (
              accountItems.map((item) => (
                <ButtonLink
                  key={item.href}
                  href={item.href}
                  onClick={(event) => handleMenuLinkClick(item.href, event)}
                  variant={headerSurface === "brand" ? "white" : item.variant}
                  size="sm"
                  fullWidth={false}
                  className="header__desktop-cta"
                  data-ui="header.accountEntry"
                  data-surface={headerSurface}
                >
                  {item.label}
                </ButtonLink>
              ))
            ) : null}
          </div>
          <button
            id="header-burger"
            ref={burgerButtonRef}
            type="button"
            aria-controls="mobile-menu"
            aria-label="Открыть меню"
            aria-hidden={menuOpen ? true : undefined}
            aria-expanded={menuOpen}
            tabIndex={menuOpen ? -1 : 0}
            onClick={(event) => toggleMenu(event.detail === 0 ? "keyboard" : "pointer")}
            className={`burger ${menuOpen ? "is-open" : ""}`}
            data-ui="header.burger"
          >
            <span className="burger__line burger__line--top" />
            <span className="burger__line burger__line--bottom" />
          </button>
        </div>
      </header>

      <dialog
        ref={menuDialogRef}
        id="mobile-menu"
        aria-label="Мобильное меню"
        className={`menu-overlay menu-overlay--island menu-overlay--${headerSurface} ${menuOpen ? "is-open" : "is-closed"}`}
        data-focus-origin={menuFocusOrigin}
        data-surface={headerSurface}
        data-ui="header.mobileMenu"
        onCancel={(event) => {
          event.preventDefault();
          closeMenu({ restoreFocus: true });
        }}
        onClose={() => {
          if (menuOpen) {
            setMenuOpen(false);
          }

          if (restoreFocusOnMenuCloseRef.current) {
            restoreFocusOnMenuCloseRef.current = false;
            window.requestAnimationFrame(() => burgerButtonRef.current?.focus());
          } else if (document.activeElement === burgerButtonRef.current) {
            // Native dialog.close() restores its opener even after a pointer close.
            burgerButtonRef.current?.blur();
          }
        }}
      >
        <button
          type="button"
          className="menu-overlay__dismiss"
          aria-label="Закрыть меню"
          tabIndex={-1}
          onClick={() => closeMenu()}
        />
        <div className="header__row menu-overlay__header">
          <Link
            href="/"
            className="header__logo menu-overlay__logo"
            data-ui="header.mobileMenu.logo"
            onClick={(event) => handleMenuLinkClick("/", event)}
          >
            <span className="header__mark" aria-hidden="true" />
            <span className="header__logo-text">christmedschool</span>
          </Link>
          <button
            ref={menuCloseButtonRef}
            type="button"
            className={`burger ${menuChromeOpen ? "is-open" : ""} menu-overlay__close`}
            aria-controls="mobile-menu"
            aria-expanded={menuOpen}
            aria-label="Закрыть меню"
            data-ui="header.mobileMenu.close"
            onClick={handleMenuCloseClick}
          >
            <span className="burger__line burger__line--top" />
            <span className="burger__line burger__line--bottom" />
          </button>
        </div>
        <nav
          className={`menu menu__panel ${menuOpen ? "is-open" : ""}`}
          aria-label="Мобильное меню"
          onClick={handleMenuPanelClick}
        >
          <div className="menu__inner page-gutter">
            <ul className="menu__list">
              {menuItems.map((item, index) => (
                <li
                  key={`${item.label}-${index}`}
                  className="menu__item"
                  style={{ ["--menu-stagger-index" as string]: index }}
                >
                  <Link
                    href={item.href}
                    onClick={(event) => handleMenuLinkClick(item.href, event)}
                    className="menu__link"
                  >
                    {item.label}
                  </Link>
                </li>
              ))}
            </ul>
            <div className="menu__actions" aria-label="Аккаунт" data-ui="header.mobileActions">
              {accountProfile ? (
                <div
                  className={`menu__account ${isImpersonating ? "menu__account--impersonating" : ""}`}
                  data-ui="header.mobileAccount"
                  data-state={isImpersonating ? "impersonating" : "default"}
                  style={{ ["--menu-stagger-index" as string]: menuItems.length }}
                >
                  {showReturnToAdmin ? (
                    <Button fullWidth={false} variant="ghost" size="md"
                      type="button"
                      onClick={() => void handleReturnToAdmin()}
                      loading={isReturningToAdmin}
                      aria-label="Вернуться в админку"
                      title="Вернуться в админку"
                      className="menu__account-impersonation"
                      data-ui="header.mobileImpersonationReturn"
                      data-state={isReturningToAdmin ? "loading" : impersonation?.status ?? "idle"}
                    >
                      <ReturnToAdminIcon />
                      <span>Вернуться в админку</span>
                    </Button>
                  ) : null}
                  <ButtonRouteLink variant="white" fullWidth={false}
                    href={accountHref}
                    prefetch={accountHref.startsWith("/admin/") ? false : null}
                    onClick={(event) => handleMenuLinkClick(accountHref, event)}
                    className="menu__account-link"
                    data-ui="header.mobileAccount.link"
                    aria-label={accountAriaLabel}
                  >
                    <AccountAvatar avatarUrl={accountAvatarUrl} />
                    <span className="menu__account-name">{accountIdentity}</span>
                  </ButtonRouteLink>
                  <Button fullWidth={false} variant="ghost" iconOnly size="sm"
                    type="button"
                    onClick={() => void handleLogout()}
                    loading={isLoggingOut}
                    aria-label="Выйти из аккаунта"
                    className="menu__account-logout"
                    data-ui="header.mobileLogoutButton"
                    data-state={isLoggingOut ? "loading" : "idle"}
                  >
                    <LogoutIcon />
                  </Button>
                </div>
              ) : showReturnToAdmin ? (
                <Button fullWidth={false} variant="ghost" size="md"
                  type="button"
                  onClick={() => void handleReturnToAdmin()}
                  loading={isReturningToAdmin}
                  aria-label="Вернуться в админку"
                  title="Вернуться в админку"
                  className="menu__account-impersonation"
                  data-ui="header.mobileImpersonationReturn"
                  data-state={isReturningToAdmin ? "loading" : impersonation?.status ?? "idle"}
                >
                  <ReturnToAdminIcon />
                  <span>Вернуться в админку</span>
                </Button>
              ) : !isProtectedAccountRoute && isAccountProfileResolved ? (
                accountItems.map((item, index) => (
                  <ButtonRouteLink
                    variant={item.variant}
                    fullWidth={false}
                    key={item.href}
                    href={item.href}
                    onClick={(event) => handleMenuLinkClick(item.href, event)}
                    className="menu__action"
                    data-ui="header.mobileAccountEntry"
                    style={{ ["--menu-stagger-index" as string]: menuItems.length + index }}
                  >
                    {item.label}
                  </ButtonRouteLink>
                ))
              ) : null}
            </div>
          </div>
        </nav>
      </dialog>

      {logoutError ? (
        <div className="header__logout-alert" role="alert" data-ui="header.logoutError">
          <span>{logoutError}</span>
          <Button fullWidth={false} variant="ghost" size="md" type="button" onClick={() => void handleLogout()} loading={isLoggingOut}>
            Повторить
          </Button>
        </div>
      ) : null}

      {impersonationError ? (
        <div className="header__logout-alert" role="alert" data-ui="header.impersonationError">
          <span>{impersonationError}</span>
          <Button fullWidth={false} variant="ghost" size="md" type="button" onClick={() => void handleReturnToAdmin()} loading={isReturningToAdmin}>
            Повторить
          </Button>
        </div>
      ) : null}

      {showFloatingCart ? (
        <div className="site-floating-cart">
        <ButtonRouteLink variant="utility" iconOnly fullWidth={false}
          href="/lk/cart"
          aria-label="Открыть корзину"
          data-ui="header.floatingCart"
          data-state={cartBump ? "highlighted" : "idle"}
          className={cartBump ? "anim-soft-pulse" : undefined}
        >
          <svg viewBox="0 0 24 24" aria-hidden="true" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth="1.8">
            <circle cx="9" cy="20" r="1.25" />
            <circle cx="17" cy="20" r="1.25" />
            <path d="M3 4h2l2.2 10.2a1 1 0 0 0 1 .8h8.6a1 1 0 0 0 1-.8L20 7H7" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </ButtonRouteLink>
          {cartCount > 0 ? (
            <span className="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-full ui-surface-ink px-1.5 text-xs font-bold leading-none text-white" data-ui="header.floatingCart.badge">
              {cartCount}
            </span>
          ) : null}
        </div>
      ) : null}
    </>
  );
}

export default function SiteHeader({ initialAccount }: { readonly initialAccount: ClientAccountResult }) {
  return <SiteHeaderContent initialAccount={initialAccount} />;
}
