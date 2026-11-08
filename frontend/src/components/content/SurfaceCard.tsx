import { ButtonHTMLAttributes, HTMLAttributes, ReactNode, type Ref } from "react";

type SurfaceCardProps = HTMLAttributes<HTMLElement> & {
  children: ReactNode;
  wide?: boolean;
  tone?: "default" | "brand" | "brand-soft" | "subject";
  variant?: "default" | "transparent" | "outlined";
};

type SurfaceButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  children: ReactNode;
};

type BrandSurfaceProps = HTMLAttributes<HTMLElement> & {
  children: ReactNode;
  from?: "all" | "non-phone" | "desktop";
  ref?: Ref<HTMLElement>;
};

export function BrandSurface({ children, className = "", from = "all", ...props }: BrandSurfaceProps) {
  return (
    <section
      {...props}
      className={["ui-brand-surface", `ui-brand-surface--${from}`, className].filter(Boolean).join(" ")}
    >
      {children}
    </section>
  );
}

export function SurfaceCard({ children, className = "", wide = false, tone = "default", variant = "default", ...props }: SurfaceCardProps) {
  return (
    <article
      {...props}
      className={["ui-surface-card", wide ? "ui-surface-card--wide" : "", tone !== "default" ? `ui-surface-card--${tone}` : "", variant !== "default" ? `ui-surface-card--${variant}` : "", className].filter(Boolean).join(" ")}
    >
      {children}
    </article>
  );
}

export function SurfaceButton({ children, className = "", type = "button", ...props }: SurfaceButtonProps) {
  return (
    <button {...props} type={type} className={["ui-surface-button", className].filter(Boolean).join(" ")}>
      {children}
    </button>
  );
}
