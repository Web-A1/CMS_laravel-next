import { AnchorHTMLAttributes, ReactNode } from "react";

type TextLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
  children: ReactNode;
  hitArea?: "default" | "minimum";
  tone?: "default" | "on-dark";
};

export function TextLinkArrow() {
  return (
    <svg className="ui-text-link__icon" viewBox="0 0 15 10" aria-hidden="true" focusable="false">
      <path
        d="M1 5H13M9.25 1.25L13 5L9.25 8.75"
        fill="none"
        stroke="currentColor"
        strokeLinecap="round"
        strokeLinejoin="round"
        strokeWidth="1.55"
        vectorEffect="non-scaling-stroke"
      />
    </svg>
  );
}

export default function TextLink({ children, className = "", hitArea = "default", tone = "default", ...props }: TextLinkProps) {
  return (
    <a
      {...props}
      className={["ui-text-link", tone === "on-dark" ? "ui-text-link--on-dark" : "", hitArea === "minimum" ? "min-h-6 min-w-6" : "", className]
        .filter(Boolean)
        .join(" ")}
    >
      <span>{children}</span>
      <TextLinkArrow />
    </a>
  );
}
