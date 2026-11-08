import type { ReactNode } from "react";
import "./page-intro-layout.css";

export default function PageIntroLayout({ title, children, search }: {
  readonly title: ReactNode;
  readonly children: ReactNode;
  readonly search: ReactNode;
}) {
  return <div className="page-intro-layout">
    <div className="page-intro-layout__content">{title}{children}</div>
    <div className="page-intro-layout__search">{search}</div>
  </div>;
}
