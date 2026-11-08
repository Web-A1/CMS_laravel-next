import type { ReactNode } from "react";

type PillStripProps = {
  readonly ariaLabel: string;
  readonly children: ReactNode;
  readonly className?: string;
};

export default function PillStrip({ ariaLabel, children, className = "" }: PillStripProps) {
  return (
    <div className={["ui-pill-strip", className].filter(Boolean).join(" ")} role="group" aria-label={ariaLabel}>
      <div className="ui-pill-strip__inner">{children}</div>
    </div>
  );
}
