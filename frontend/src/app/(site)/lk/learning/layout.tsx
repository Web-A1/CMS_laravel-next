import type { ReactNode } from "react";

import { LearningSidebarFrame } from "@/components/learning/LearningSidebarFrame";

type LearningLayoutProps = {
  readonly children: ReactNode;
  readonly sidebar: ReactNode;
};

export default function LearningLayout({ children, sidebar }: LearningLayoutProps) {
  return (
    <div
      className="lk-learning-detail-layout content-shell page-content page-gutter mx-auto grid w-full min-w-0 gap-6 bg-white ui-text-primary lg:grid-cols-[12.5rem_minmax(0,1fr)] lg:items-start"
      data-ui="lk.learning.layout"
    >
      <LearningSidebarFrame>{sidebar}</LearningSidebarFrame>
      <div className="min-w-0" data-ui="lk.learning.main">
        {children}
      </div>
    </div>
  );
}
