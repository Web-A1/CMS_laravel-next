import type { ReactNode } from "react";

type AdminPageHeaderProps = {
  readonly title: string;
  readonly id?: string;
  readonly trailing?: ReactNode;
  readonly children?: ReactNode;
  readonly className?: string;
};

export function AdminPageHeader({ title, id, trailing, children, className = "" }: AdminPageHeaderProps) {
  return (
    <div className={`admin-page-header ${className}`.trim()}>
      <div className="flex min-w-0 items-center gap-2">
        <h1 id={id} className="admin-page-title">{title}</h1>
        {children}
      </div>
      {trailing}
    </div>
  );
}
