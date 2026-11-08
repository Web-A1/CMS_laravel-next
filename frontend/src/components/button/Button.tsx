"use client";

import Link from "next/link";
import { forwardRef, useImperativeHandle, useLayoutEffect, useRef, type AnchorHTMLAttributes, type ButtonHTMLAttributes, type ComponentProps, type ForwardedRef, type MouseEvent, type PointerEvent, type ReactNode } from "react";
import { buttonState, revealGeometry } from "./buttonState";

export type ButtonVariant = "primary" | "secondary" | "accent" | "white" | "ghost" | "utility" | "photo" | "glass";
export type ButtonSize = "sm" | "md";

type ButtonAppearance = {
  children?: ReactNode;
  className?: string;
  fullWidth?: boolean;
  iconOnly?: boolean;
  variant?: ButtonVariant;
  size?: ButtonSize;
};
type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & ButtonAppearance & { label?: string; loading?: boolean };
type ButtonLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & ButtonAppearance & { disabled?: boolean };
type ButtonRouteLinkProps = ComponentProps<typeof Link> & ButtonAppearance & { disabled?: boolean };
const navigationProps = new Set(["href", "as", "replace", "scroll", "shallow", "passHref", "prefetch", "locale", "legacyBehavior", "onNavigate"]);

function buttonClassName({ className = "", fullWidth = true, iconOnly = false, variant = "primary", size = "md" }: ButtonAppearance) {
  return ["button", `button--${variant}`, `button--${size}`, fullWidth && !iconOnly ? "button--full" : "", iconOnly ? "button--icon-only" : "", className].filter(Boolean).join(" ");
}

function revealFromPointer(event: PointerEvent<HTMLElement>) {
  if (event.pointerType === "touch") return;
  const element = event.currentTarget;
  const rect = element.getBoundingClientRect();
  const { x, y, radius } = revealGeometry(rect.width, rect.height, event.clientX - rect.left, event.clientY - rect.top);
  element.style.setProperty("--button-reveal-x", `${x}px`);
  element.style.setProperty("--button-reveal-y", `${y}px`);
  element.style.setProperty("--button-reveal-radius", `${radius}px`);
}

function blockActivation(event: MouseEvent<HTMLElement>) {
  event.preventDefault();
  event.stopPropagation();
}

// The duplicate is presentational: the reveal changes ink and label together.
// Children must be text/icons, never interactive elements or SVGs with document IDs.
function ButtonContent({ children, loading = false, variant = "primary" }: { children: ReactNode; loading?: boolean; variant?: ButtonVariant }) {
  const reveal = variant !== "ghost" && variant !== "utility" && variant !== "photo";
  return <>
    <span className="button__content">{children}</span>
    {reveal ? <span className="button__reveal" aria-hidden="true" inert><span className="button__content">{children}</span></span> : null}
    {loading ? <span className="button__spinner" aria-hidden="true" /> : null}
  </>;
}

export const ButtonLink = forwardRef<HTMLAnchorElement, ButtonLinkProps>(function ButtonLink(
  { children, className, fullWidth = true, iconOnly, variant, size, disabled, href, onPointerEnter, onClick, tabIndex, "aria-disabled": ariaDisabled, ...props }, ref,
) {
  const { blocked } = buttonState({ disabled, ariaDisabled });
  return <a {...props} href={blocked ? undefined : href} role={blocked ? "link" : props.role} ref={ref} className={buttonClassName({ className, fullWidth, iconOnly, variant, size })}
    aria-disabled={blocked || undefined} tabIndex={blocked ? -1 : tabIndex}
    onPointerEnter={(event) => { if (!blocked) revealFromPointer(event); onPointerEnter?.(event); }}
    onClick={(event) => { if (blocked) blockActivation(event); else onClick?.(event); }}>
    <ButtonContent variant={variant}>{children}</ButtonContent>
  </a>;
});

export const ButtonRouteLink = forwardRef<HTMLAnchorElement, ButtonRouteLinkProps>(function ButtonRouteLink(
  { children, className, fullWidth = true, iconOnly, variant, size, disabled, onPointerEnter, onClick, tabIndex, "aria-disabled": ariaDisabled, ...props }, ref,
) {
  const { blocked } = buttonState({ disabled, ariaDisabled });
  if (blocked) {
    // A disabled link has no destination, including middle-click and the context menu.
    const anchorProps = Object.fromEntries(Object.entries(props).filter(([name]) => !navigationProps.has(name)));
    return <ButtonLink {...anchorProps} ref={ref} className={className} fullWidth={fullWidth} iconOnly={iconOnly} variant={variant} size={size} disabled>{children}</ButtonLink>;
  }
  return <Link {...props} ref={ref} className={buttonClassName({ className, fullWidth, iconOnly, variant, size })}
    aria-disabled={blocked || undefined} tabIndex={blocked ? -1 : tabIndex}
    onPointerEnter={(event) => { if (!blocked) revealFromPointer(event); onPointerEnter?.(event); }}
    onClick={(event) => { if (blocked) blockActivation(event); else onClick?.(event); }}>
    <ButtonContent variant={variant}>{children}</ButtonContent>
  </Link>;
});

function useButtonGeometry(forwardedRef: ForwardedRef<HTMLButtonElement>, loading: boolean) {
  const elementRef = useRef<HTMLButtonElement | null>(null);
  useImperativeHandle<HTMLButtonElement | null, HTMLButtonElement | null>(forwardedRef, () => elementRef.current, []);

  useLayoutEffect(() => {
    const element = elementRef.current;
    if (!element || loading) return;
    const rememberSize = () => {
      const { width, height } = element.getBoundingClientRect();
      if (!width || !height) return;
      element.style.setProperty("--button-loading-width", `${width}px`);
      element.style.setProperty("--button-loading-height", `${height}px`);
    };
    rememberSize();
    const observer = new ResizeObserver(rememberSize);
    observer.observe(element);
    return () => observer.disconnect();
  }, [loading]);
  return elementRef;
}

const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { children, label, loading = false, disabled, className, fullWidth = true, iconOnly, variant, size, onPointerEnter, onClick, "aria-disabled": ariaDisabled, ...props }, ref,
) {
  const state = buttonState({ disabled, loading, ariaDisabled });
  const measuredRef = useButtonGeometry(ref, loading);
  return <button {...props} ref={measuredRef} disabled={state.nativeDisabled} aria-disabled={state.ariaDisabled || undefined}
    aria-busy={loading || props["aria-busy"]} data-loading={loading || undefined}
    className={buttonClassName({ className, fullWidth, iconOnly, variant, size })}
    onPointerEnter={(event) => { if (!state.blocked) revealFromPointer(event); onPointerEnter?.(event); }}
    onClick={(event) => { if (state.blocked) blockActivation(event); else onClick?.(event); }}>
    <ButtonContent loading={loading} variant={variant}>{children ?? label}</ButtonContent>
  </button>;
});

export default Button;
