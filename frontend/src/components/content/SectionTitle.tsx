import { HTMLAttributes, ReactNode } from "react";

type SectionTitleProps = HTMLAttributes<HTMLHeadingElement> & {
  children: ReactNode;
  tone?: "default" | "on-dark";
};

export default function SectionTitle({ children, className = "", tone = "default", ...props }: SectionTitleProps) {
  return (
    <h2
      {...props}
      className={["ui-section-title", tone === "on-dark" ? "ui-section-title--on-dark" : "", className]
        .filter(Boolean)
        .join(" ")}
    >
      {children}
    </h2>
  );
}
