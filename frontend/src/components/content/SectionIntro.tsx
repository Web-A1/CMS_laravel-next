import type { ReactNode } from "react";

type SectionIntroProps = {
  readonly eyebrow: string;
  readonly children: ReactNode;
  readonly className?: string;
  readonly dataUi?: string;
};

export default function SectionIntro({ eyebrow, children, className = "", dataUi }: SectionIntroProps) {
  return (
    <div className={`ui-section-intro ${className}`}>
      <h2 className="mb-3 text-xs font-medium uppercase ui-text-muted-ink" data-ui={dataUi ? `${dataUi}.title` : undefined}>
        {eyebrow}
      </h2>
      <p className="ui-section-title" data-ui={dataUi ? `${dataUi}.tagline` : undefined}>
        {children}
      </p>
    </div>
  );
}
