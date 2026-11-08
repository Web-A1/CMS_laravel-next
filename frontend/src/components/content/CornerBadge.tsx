import type { HTMLAttributes, ReactNode } from "react";

type CornerBadgeProps = HTMLAttributes<HTMLElement> & {
  readonly as?: "span" | "h2";
  readonly children: ReactNode;
  readonly withHover?: boolean;
};

export function CornerBadge({ as: Tag = "span", children, className = "", withHover = false, ...props }: CornerBadgeProps) {
  return <Tag {...props} className={["ui-status-pill", "ui-status-pill--corner", withHover ? "ui-status-pill--hoverable" : "", className].filter(Boolean).join(" ")}>{children}</Tag>;
}
